<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Processador de PDFs - NativePHP</title>
    
    <!-- CSS Externo -->
    <link rel="stylesheet" href="{{ asset('css/style.css') }}">
</head>
<body>

    <header>
        <h1>📄 Processador de PDFs</h1>
        <button id="theme-toggle" class="theme-toggle-btn" type="button">
            <span id="theme-icon">🌙</span>
            <span id="theme-text">Modo Escuro</span>
        </button>
    </header>

    <main>
        <div class="card">
            <h2>Enviar Documentos em Lote</h2>
            <br>
            <form id="upload-form">
                <div class="form-group">
                    <input type="file" id="arquivo_pdf" name="pdfs[]" multiple accept="application/pdf" required>
                </div>
                <button type="submit" id="btn-submit" class="btn-submit">Iniciar Processamento</button>
            </form>
        </div>

        <div id="progresso-geral"></div>
        <div id="painelFila"></div>
    </main>

    <!-- JS Externo -->
    <script src="{{ asset('js/upload.js') }}"></script>
</body>
</html>