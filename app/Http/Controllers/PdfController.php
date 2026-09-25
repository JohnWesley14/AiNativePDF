<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser;

class PdfController extends Controller
{
    /**
     * Equivalente ao antigo upload_lote.php
     */
    public function uploadLote(Request $request)
    {
        if (!$request->hasFile('pdfs')) {
            return response()->json(['sucesso' => false, 'erro' => 'Nenhum arquivo enviado.'], 400);
        }

        try {
            $idsInseridos = [];
            $files = $request->file('pdfs');

            foreach ($files as $file) {
                if (!$file->isValid()) {
                    continue;
                }

                $nomeOriginal = $file->getClientOriginalName();
                $nomeLimpo = preg_replace('/[^a-zA-Z0-9.\-_]/', '', $nomeOriginal);

                $nomeUnico = uniqid() . '_' . $nomeLimpo;

                // 1. Salva o arquivo no disco local
                $caminhoRelativo = $file->storeAs('uploads', $nomeUnico, 'local');

                // 2. Pega o caminho absoluto real e exato onde o Laravel gravou o arquivo
                $caminhoAbsoluto = Storage::disk('local')->path($caminhoRelativo);

                // Insere registro no banco
                $id = DB::table('fila_pdf')->insertGetId([
                    'nome_arquivo' => $nomeOriginal,
                    'caminho_temp' => $caminhoAbsoluto,
                    'status' => 'pendente'
                    // O campo criado_em é preenchido automaticamente pelo SQLite
                ]);

                $idsInseridos[] = [
                    'id' => $id,
                    'nome' => $nomeOriginal
                ];
            }

            return response()->json(['sucesso' => true, 'itens' => $idsInseridos]);
        } catch (\Exception $e) {
            return response()->json(['sucesso' => false, 'erro' => $e->getMessage()], 500);
        }
    }

    /**
     * Equivalente ao antigo processar_item.php
     */
    public function processarItem($id)
    {
        set_time_limit(600);

        try {
            $item = DB::table('fila_pdf')->where('id', $id)->first();

            if (!$item) {
                return response()->json(['sucesso' => false, 'erro' => 'Item não encontrado.'], 404);
            }

            DB::table('fila_pdf')->where('id', $id)->update(['status' => 'processando']);

            // Define o caminho dinâmico para a pasta do Laravel
            $caminhoPdfToText = storage_path('app/poppler/bin/pdftotext.exe');

            $textoExtraido = '';

            // 1. Tenta usar o Poppler
            if (file_exists($caminhoPdfToText)) {
                $pdfSeguro = escapeshellarg($item->caminho_temp);
                $saida = shell_exec("$caminhoPdfToText -enc UTF-8 $pdfSeguro -");
                $textoExtraido = $saida ?? '';
            }
            // 2. Se o Poppler não for encontrado, usa o Smalot PdfParser instalado via Composer
            else if (class_exists(\Smalot\PdfParser\Parser::class)) {
                $parser = new \Smalot\PdfParser\Parser();
                $pdfParsed = $parser->parseFile($item->caminho_temp);
                $textoExtraido = $pdfParsed->getText();
            } else {
                throw new \Exception("Nenhum extrator de PDF disponível. Verifique a instalação do Poppler ou Smalot/PdfParser.");
            }

            $textoExtraido = trim(mb_substr(preg_replace('/\s+/', ' ', $textoExtraido), 0, 10000, 'UTF-8'));

            if (empty($textoExtraido)) {
                throw new \Exception("PDF vazio ou imagem escaneada.");
            }

            // 2. Chamada à IA
            $retorno = $this->analisarTextoComOllama($textoExtraido);
            $resultadoIA = is_array($retorno['dados']) ? $retorno['dados'] : [];

            if (empty($resultadoIA)) {
                throw new \Exception("A IA falhou ao gerar o formato correto.");
            }

            // 3. Verificação de Duplicidade e Salvamento
            $hashArquivo = md5_file($item->caminho_temp);
            $jaExiste = DB::table('relatorios')->where('hash_arquivo', $hashArquivo)->exists();

            if (!$jaExiste) {
                $dataExpiracao = $resultadoIA['data_expiracao'] ?? null;
                if (empty($dataExpiracao) || strtolower($dataExpiracao) === 'null' || $dataExpiracao === 'Não consta') {
                    $dataExpiracao = null;
                }

                DB::table('relatorios')->insert([
                    'create_time' => now(),
                    'titulo' => $resultadoIA['titulo'] ?? $item->nome_arquivo,
                    'descricao' => $resultadoIA['conteudo'] ?? 'Sem conteúdo',
                    'data_expiracao' => $dataExpiracao,
                    'hash_arquivo' => $hashArquivo
                ]);
            }

            // 4. Finalização
            DB::table('fila_pdf')->where('id', $id)->update(['status' => 'concluido']);
            @unlink($item->caminho_temp);

            return response()->json([
                'sucesso' => true,
                'dados' => $resultadoIA,
                'tempo_ia' => $retorno['tempo_ia']
            ]);
        } catch (\Exception $e) {
            DB::table('fila_pdf')->where('id', $id)->update([
                'status' => 'erro',
                'mensagem_erro' => $e->getMessage()
            ]);

            return response()->json(['sucesso' => false, 'erro' => $e->getMessage()], 500);
        }
    }

    private function analisarTextoComOllama($textoExtraido)
    {
        $inicio = microtime(true);

        $instrucao = "Analise o texto do documento abaixo. " .
            "Retorne os dados extraídos EXCLUSIVAMENTE em um objeto JSON válido, contendo as seguintes chaves:\n" .
            "\"titulo\": \"(Escreva aqui o título ou assunto)\",\n" .
            "\"conteudo\": \"(Escreva aqui o resumo de até 3 linhas)\",\n" .
            "\"data_expiracao\": \"(Retorne APENAS a data no formato AAAA-MM-DD. Se não houver data, retorne null)\"\n\n" .
            "--- TEXTO DO DOCUMENTO ---\n" . $textoExtraido;

        // Usa o cliente HTTP nativo do Laravel com timeout estendido de 600 segundos
        $response = Http::timeout(600)->post('http://127.0.0.1:11434/api/generate', [
            'model' => 'gemma2:latest',
            'format' => 'json',
            'prompt' => $instrucao,
            'stream' => false,
            'options' => [
                'temperature' => 0.0,
                'num_ctx' => 1024,
                'num_predict' => 300
            ]
        ]);

        if ($response->failed()) {
            throw new \Exception("Falha de comunicação com o Ollama.");
        }

        $dados = $response->json();
        $dadosExtraidos = json_decode($dados['response'] ?? '', true);

        if (!is_array($dadosExtraidos)) {
            throw new \Exception("A IA gerou uma resposta fora do formato JSON esperado.");
        }

        return [
            'dados' => $dadosExtraidos,
            'tempo_ia' => round(microtime(true) - $inicio, 2)
        ];
    }
}
