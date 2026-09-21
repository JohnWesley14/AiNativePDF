<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    /**
     * Equivalente ao antigo stream.php (Server-Sent Events)
     */
    public function stream(Request $request)
    {
        $prompt = $request->input('prompt');

        if (empty($prompt)) {
            return response()->json(['erro' => 'Prompt vazio'], 400);
        }

        return new StreamedResponse(function () use ($prompt) {
            set_time_limit(0);

            $options = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => json_encode([
                        'model'  => 'mistral-nemo',
                        'prompt' => $prompt,
                        'system' => "Responda somente em português ou inglês, seja conciso e direto",
                        'stream' => true
                    ]),
                    'timeout' => 300
                ]
            ];

            $context = stream_context_create($options);
            $stream  = @fopen('http://localhost:11434/api/generate', 'r', false, $context);

            if (!$stream) {
                echo "data: Erro ao conectar ao Ollama.\n\n";
                ob_flush();
                flush();
                return;
            }

            while (!feof($stream)) {
                $line = fgets($stream);
                if ($line !== false) {
                    $json = json_decode($line, true);
                    if (isset($json['response'])) {
                        echo "data: " . json_encode($json['response']) . "\n\n";
                        if (ob_get_level() > 0) ob_flush();
                        flush();
                    }
                }
            }

            fclose($stream);
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}