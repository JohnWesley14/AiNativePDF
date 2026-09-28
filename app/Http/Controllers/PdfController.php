<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Native\Desktop\Facades\Notification;
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
            $files =$request->file('pdfs');

            foreach ($files as$file) {
                if (!$file->isValid()) {
                    continue;
                }

                $nomeOriginal =$file->getClientOriginalName();
                $nomeLimpo = preg_replace('/[^a-zA-Z0-9.\-_]/', '',$nomeOriginal);
                $nomeUnico = uniqid() . '_' .$nomeLimpo;

                // 1. Salva o arquivo no disco local
                $caminhoRelativo = $file->storeAs('uploads',$nomeUnico, 'local');

                // 2. Pega o caminho absoluto real
                $caminhoAbsoluto = Storage::disk('local')->path($caminhoRelativo);

                // Insere registro no banco
                $id = DB::table('fila_pdf')->insertGetId([
                    'nome_arquivo' => $nomeOriginal,
                    'caminho_temp' => $caminhoAbsoluto,
                    'status' => 'pendente'
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
            $item = DB::table('fila_pdf')->where('id',$id)->first();

            if (!$item) {
                return response()->json(['sucesso' => false, 'erro' => 'Item não encontrado.'], 404);
            }

            DB::table('fila_pdf')->where('id', $id)->update(['status' => 'processando']);

            // Define o caminho dinâmico para a pasta do Laravel
            $caminhoPdfToText = storage_path('app/poppler/bin/pdftotext.exe');

            $textoExtraido = '';

            // 1. Tenta usar o Poppler
            if (file_exists($caminhoPdfToText)) {$pdfSeguro = escapeshellarg($item->caminho_temp);$saida = shell_exec("$caminhoPdfToText -enc UTF-8$pdfSeguro -");
                $textoExtraido =$saida ?? '';
            }
            // 2. Se o Poppler não for encontrado, usa o Smalot PdfParser
            else if (class_exists(\Smalot\PdfParser\Parser::class)) {
                $parser = new \Smalot\PdfParser\Parser();$pdfParsed = $parser->parseFile($item->caminho_temp);
                $textoExtraido =$pdfParsed->getText();
            } else {
                throw new \Exception("Nenhum extrator de PDF disponível. Verifique a instalação do Poppler ou Smalot/PdfParser.");
            }

            // Ajustado para 6.000 caracteres (~1.500 tokens) para caber com folga no contexto
            $textoExtraido = trim(mb_substr(preg_replace('/\s+/', ' ',$textoExtraido), 0, 6000, 'UTF-8'));

            if (empty($textoExtraido)) {
                throw new \Exception("PDF vazio ou imagem escaneada.");
            }

            // 2. Chamada à IA
            $retorno =$this->analisarTextoComOllama($textoExtraido);$resultadoIA = is_array($retorno['dados']) ?$retorno['dados'] : [];

            if (empty($resultadoIA)) {
                throw new \Exception("A IA falhou ao gerar o formato correto.");
            }

            // 3. Verificação de Duplicidade e Salvamento
            $hashArquivo = md5_file($item->caminho_temp);
            $jaExiste = DB::table('relatorios')->where('hash_arquivo',$hashArquivo)->exists();

            if (!$jaExiste) {
                $dataExpiracao =$resultadoIA['data_expiracao'] ?? null;
                if (empty($dataExpiracao) || strtolower($dataExpiracao) === 'null' ||$dataExpiracao === 'Não consta') {
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

            Notification::title("Análise bem sucedida")->message("O arquivo '{$item->nome_arquivo}' foi analisado e já consta no banco de dados")->show();

            return response()->json([
                'sucesso' => true,
                'dados' => $resultadoIA,
                'tempo_ia' => $retorno['tempo_ia'],
                'ja_existe' => $jaExiste
            ]);
        } catch (\Exception $e) {
            DB::table('fila_pdf')->where('id', $id)->update([
                'status' => 'erro',
                'mensagem_erro' => $e->getMessage()
            ]);
            Notification::title("Falha na análise")->message("Ocorreu um erro ao tentar analisar o arquivo: '{$item->nome_arquivo}'")->show();
            return response()->json(['sucesso' => false, 'erro' => $e->getMessage()], 500);
        }
    }

    private function analisarTextoComOllama($textoExtraido)
    {
        $inicio = microtime(true);

        $instrucao = "Analise o texto do documento abaixo.\n" .
            "Retorne os dados extraídos EXCLUSIVAMENTE como um objeto JSON válido, sem explicações extras ou marcações markdown.\n" .
            "Esquema das chaves:\n" .
            "{\n" .
            "  \"titulo\": \"(Título ou assunto principal)\",\n" .
            "  \"conteudo\": \"(Resumo sucinto de até 3 linhas)\",\n" .
            "  \"data_expiracao\": \"(Data no formato AAAA-MM-DD ou null se não constar)\"\n" .
            "}\n\n" .
            "--- TEXTO DO DOCUMENTO ---\n" . $textoExtraido;

        $response = Http::timeout(600)->post('http://127.0.0.1:11434/api/generate', [
            'model' => 'gemma2:latest',
            'format' => 'json',
            'prompt' => $instrucao,
            'stream' => false,
            'options' => [
                'temperature' => 0.0,
                'num_ctx' => 4096,     
                'num_predict' => 512   
            ]
        ]);

        if ($response->failed()) {
            throw new \Exception("Falha de comunicação com o Ollama.");
        }

        $dados =$response->json();
        $respostaBruta =$dados['response'] ?? '';

        // 1. Remove formatações Markdown que a IA possa ter inserido
        $conteudoLimpo = preg_replace('/```(?:json)?/i', '', $respostaBruta);

        // 2. Isola apenas a estrutura entre o primeiro '{' e o último '}'
        if (preg_match('/\{.*\}/s', $conteudoLimpo, $matches)) {
            $conteudoLimpo = $matches[0];
        }

        $dadosExtraidos = json_decode(trim($conteudoLimpo), true);

        // Se o parsing falhar, salva no log do Laravel para análise precisa
        if (!is_array($dadosExtraidos)) {
            Log::error("Falha ao decodificar JSON do Ollama no arquivo. Resposta bruta: " . $respostaBruta);
            throw new \Exception("A IA gerou uma resposta fora do formato JSON esperado.");
        }

        return [
            'dados' => $dadosExtraidos,
            'tempo_ia' => round(microtime(true) - $inicio, 2)
        ];
    }
}