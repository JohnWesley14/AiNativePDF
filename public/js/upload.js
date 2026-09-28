document.addEventListener('DOMContentLoaded', () => {

    /* ==========================================================================
       1. DECLARAÇÃO DE ELEMENTOS NO TOPO (Evita ReferenceError)
       ========================================================================== */
    const themeToggleBtn = document.getElementById('theme-toggle');
    const themeIcon = document.getElementById('theme-icon');
    const themeText = document.getElementById('theme-text');

    const form = document.getElementById('upload-form');
    const arquivoInput = document.getElementById('arquivo_pdf');
    const fileLabelText = document.getElementById('file-label-text');
    const btnSubmit = document.getElementById('btn-submit');
    const painelFila = document.getElementById('painelFila');
    const progressoGeral = document.getElementById('progresso-geral');

    /* ==========================================================================
       2. LÓGICA DE TEMA (DARK / LIGHT)
       ========================================================================== */
    const currentTheme = localStorage.getItem('app_theme') || 'dark';
    aplicarTema(currentTheme);

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', () => {
            const temaAtual = document.documentElement.getAttribute('data-theme') || 'dark';
            const novoTema = temaAtual === 'dark' ? 'light' : 'dark';

            aplicarTema(novoTema);
            localStorage.setItem('app_theme', novoTema);
        });
    }

    function aplicarTema(tema) {
        document.documentElement.setAttribute('data-theme', tema);
        if (themeIcon && themeText) {
            if (tema === 'dark') {
                themeIcon.innerText = '🌙';
                themeText.innerText = 'Modo Escuro';
            } else {
                themeIcon.innerText = '☀️';
                themeText.innerText = 'Modo Claro';
            }
        }
    }

    /* ==========================================================================
       3. FEEDBACK VISUAL DO SELECIONADOR DE ARQUIVOS
       ========================================================================== */
    if (arquivoInput) {
        arquivoInput.addEventListener('change', (e) => {
            const total = e.target.files.length;
            if (fileLabelText) {
                if (total > 0) {
                    fileLabelText.innerText = `📄 ${total} arquivo(s) selecionado(s)`;
                } else {
                    fileLabelText.innerText = 'Clique ou arraste seus arquivos PDF aqui';
                }
            }
        });
    }

    /* ==========================================================================
       4. ENVIO DE FORMULÁRIO E FILA DE PROCESSAMENTO
       ========================================================================== */
    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!arquivoInput || arquivoInput.files.length === 0) return;

            btnSubmit.disabled = true;
            progressoGeral.style.display = 'block';
            progressoGeral.style.color = 'var(--text-color)';
            progressoGeral.innerText = '⏳ Fazendo upload dos arquivos...';
            painelFila.innerHTML = '';

            const formData = new FormData();
            for (let file of arquivoInput.files) {
                formData.append('pdfs[]', file);
            }

            try {
                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

                // 1. Envia lote para a API
                const response = await fetch('/api/upload-lote', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    body: formData
                });
                const result = await response.json();

                if (!result.sucesso) throw new Error(result.erro || 'Erro ao registrar lote.');

                // 2. Cria os cards na tela
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

                // 3. Executa a análise sequencial
                for (let item of result.itens) {
                    const cardEl = document.getElementById(`card-${item.id}`);
                    const statusEl = document.getElementById(`status-${item.id}`);
                    const detalhesEl = document.getElementById(`detalhes-${item.id}`);

                    if (cardEl) cardEl.className = 'queue-item processando';
                    if (statusEl) statusEl.innerText = 'Em Análise...';

                    try {
                        const resProcess = await fetch(`/api/processar-item/${item.id}`);
                        const resData = await resProcess.json();

                        if (resData.sucesso) {
                            if (cardEl) cardEl.className = 'queue-item concluido';

                            // 1. Atualiza a tag de status no topo do card
                            if (statusEl) {
                                if (resData.ja_existe) {
                                    statusEl.innerText = '⚠️ Já Cadastrado';
                                    statusEl.style.color = '#e67e22'; // Cor Laranja / Aviso
                                } else {
                                    statusEl.innerText = 'Concluído';
                                }
                            }

                            const dados = resData.dados || {};

                            // 2. Exibe uma mensagem explicativa nos detalhes do card
                            if (detalhesEl) {
                                detalhesEl.style.display = 'block';

                                const avisoDuplicado = resData.ja_existe? 
                                `<div style="background: rgba(230, 126, 34, 0.15); color: #e67e22; padding: 6px 10px; border-radius: 4px; font-size: 12px; margin-bottom: 8px; border: 1px solid rgba(230, 126, 34, 0.3);"> <strong>Aviso:</strong> Este arquivo já constava no banco de dados. Os dados antigos foram preservados. </div>`
                                : '';

                                detalhesEl.innerHTML = `
                                ${avisoDuplicado}
                                <strong>Título:</strong> ${dados.titulo || 'Não identificado pela IA'}<br>
                                <strong>Conteúdo:</strong> ${dados.conteudo || 'Não identificado'}<br>
                                <strong>Expiração:</strong> ${dados.data_expiracao || 'Não consta'}<br>
                                <span style="font-size: 12px; color: var(--text-muted);">⏱️ Tempo: ${resData.tempo_ia}s</span>
                            `;
                            }
                        }
                    } catch (err) {
                        if (cardEl) cardEl.className = 'queue-item erro';
                        if (statusEl) statusEl.innerText = 'Falha';
                        if (detalhesEl) {
                            detalhesEl.style.display = 'block';
                            detalhesEl.innerHTML = `<span style="color: var(--danger-color);"><strong>Erro:</strong> ${err.message}</span>`;
                        }
                    }
                }

                progressoGeral.innerText = '✅ Lote finalizado!';
                progressoGeral.style.color = 'var(--success-color)';

            } catch (error) {
                alert('Erro na comunicação com o servidor: ' + error.message);
                progressoGeral.style.display = 'none';
            } finally {
                btnSubmit.disabled = false;
                arquivoInput.value = '';
                if (fileLabelText) fileLabelText.innerText = 'Clique ou arraste seus arquivos PDF aqui';
            }
        });
    }
});