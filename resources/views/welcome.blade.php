<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Análise de Processos em Lote com IA</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            max-width: 700px;
            margin: 40px auto;
            padding: 20px;
            background-color: #f0f2f5;
        }

        .main-card {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        h2 {
            color: #333;
            margin-top: 0;
        }

        p {
            color: #555;
            margin-bottom: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        input[type="file"] {
            display: block;
            margin-top: 10px;
            width: 100%;
            padding: 10px;
            border: 2px dashed #007bff;
            border-radius: 5px;
            cursor: pointer;
            background: #f8fbff;
            box-sizing: border-box;
        }

        button {
            width: 100%;
            padding: 12px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            transition: 0.3s;
            box-sizing: border-box;
        }

        button:disabled {
            background: #95a5a6;
            cursor: not-allowed;
        }

        button:hover:not(:disabled) {
            background: #0056b3;
        }

        .progresso-geral {
            text-align: center;
            font-weight: bold;
            color: #007bff;
            display: none;
            margin-top: 20px;
            font-size: 15px;
        }

        #painelFila {
            margin-top: 30px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .queue-item {
            background: white;
            border-radius: 8px;
            padding: 15px 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
            display: flex;
            flex-direction: column;
            border-left: 5px solid #ccc;
            transition: background 0.3s;
        }

        .queue-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-weight: bold;
            color: #2c3e50;
            font-size: 15px;
        }

        .queue-status {
            font-size: 13px;
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-block;
        }

        .queue-details {
            margin-top: 15px;
            font-size: 14px;
            color: #34495e;
            display: none;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 6px;
            border: 1px solid #e9ecef;
            line-height: 1.5;
        }

        .pendente {
            border-left-color: #ffc107;
        }

        .pendente .queue-status {
            background: #fff3cd;
            color: #856404;
        }

        .processando {
            border-left-color: #17a2b8;
        }

        .processando .queue-status {
            background: #e3f2fd;
            color: #0056b3;
        }

        .concluido {
            border-left-color: #28a745;
        }

        .concluido .queue-status {
            background: #d4edda;
            color: #155724;
        }

        .erro {
            border-left-color: #dc3545;
        }

        .erro .queue-status {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
</head>

<body>
    <div class="main-card">
        <h2>Leitor Inteligente de PDF (Lote)</h2>
        <p>Faça upload de múltiplos documentos PDF. Eles serão processados um a um pela IA.</p>
        <form id="upload-form">
            <div class="form-group">
                <input type="file" id="arquivo_pdf" accept=".pdf" multiple required>
            </div>
            <button type="submit" id="btn-submit">Analisar PDFs</button>
        </form>
        <div id="progresso-geral" class="progresso-geral">⏳ Iniciando processamento do lote...</div>
    </div>
    <div id="painelFila"></div>

    <script>
        const form = document.getElementById('upload-form');
        const arquivoInput = document.getElementById('arquivo_pdf');
        const btnSubmit = document.getElementById('btn-submit');
        const painelFila = document.getElementById('painelFila');
        const progressoGeral = document.getElementById('progresso-geral');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (arquivoInput.files.length === 0) return;

            btnSubmit.disabled = true;
            progressoGeral.style.display = 'block';
            progressoGeral.innerText = '⏳ Fazendo upload dos arquivos...';
            painelFila.innerHTML = '';

            const formData = new FormData();
            for (let file of arquivoInput.files) {
                formData.append('pdfs[]', file);
            }

            try {

                const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

                const response = await fetch('/api/upload-lote', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: formData
                });
                const result = await response.json();

                if (!result.sucesso) throw new Error(result.erro || 'Erro ao registrar lote.');

                result.itens.forEach(item => {
                    painelFila.innerHTML += `
                        <div class="queue-item pendente" id="card-${item.id}">
                            <div class="queue-item-header">
                                <span class="queue-name">📄 ${item.nome}</span>
                                <span class="queue-status" id="status-${item.id}">Na fila</span>
                            </div>
                            <div class="queue-details" id="detalhes-${item.id}"></div>
                        </div>
                    `;
                });

                progressoGeral.innerText = '⚡ Processando fila pela IA...';

                for (let item of result.itens) {
                    const cardEl = document.getElementById(`card-${item.id}`);
                    const statusEl = document.getElementById(`status-${item.id}`);
                    const detalhesEl = document.getElementById(`detalhes-${item.id}`);

                    cardEl.className = 'queue-item processando';
                    statusEl.innerText = 'Em Análise...';

                    try {
                        // Novo formato RESTful do Laravel:
                        const resProcess = await fetch(`/api/processar-item/${item.id}`);
                        const resData = await resProcess.json();

                        if (resData.sucesso) {
                            cardEl.className = 'queue-item concluido';
                            statusEl.innerText = 'Concluído';

                            // Cria um objeto vazio se 'dados' não existir, evitando o erro de "null"
                            const dados = resData.dados || {};

                            detalhesEl.style.display = 'block';
                            detalhesEl.innerHTML = `
                                <strong>Título:</strong> ${dados.titulo || 'Não identificado pela IA'}<br><br>
                                <strong>Conteúdo:</strong> ${dados.conteudo || 'Não identificado'}<br><br>
                                <strong>Expiração:</strong> ${dados.data_expiracao || 'Não consta'}<br><br>
                                <span style="font-size: 12px; color: #888;">⏱️ Tempo: ${resData.tempo_ia}s</span>
                            `;
                        } else {
                            throw new Error(resData.erro);
                        }
                    } catch (err) {
                        cardEl.className = 'queue-item erro';
                        statusEl.innerText = 'Falha';
                        detalhesEl.style.display = 'block';
                        detalhesEl.innerHTML = `<span style="color:#dc3545;"><strong>Erro:</strong> ${err.message}</span>`;
                    }
                }
                progressoGeral.innerText = '✅ Lote finalizado!';
                progressoGeral.style.color = '#28a745';
            } catch (error) {
                alert('Erro na comunicação com o servidor: ' + error.message);
                progressoGeral.style.display = 'none';
            } finally {
                btnSubmit.disabled = false;
                arquivoInput.value = '';
            }
        });
    </script>
</body>

</html>