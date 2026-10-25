<?php

namespace App\Services\Growth;

final class ConversationImport
{
    public function preview(string $json): array
    {
        abort_if(strlen($json) > 2_000_000, 422, 'حداکثر اندازه ورود دو مگابایت است.');
        try {
            $root = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422, 'JSON معتبر لازم است.');
        }
        if (! is_array($root)) {
            abort(422);
        }
        $conversations = array_is_list($root) ? $root : ($root['conversations'] ?? [$root]);
        abort_if(count($conversations) > 100, 422, 'در هر نوبت حداکثر ۱۰۰ گفتگو انتخاب کنید.');
        $result = [];
        $redactor = app(Redactor::class);
        foreach ($conversations as $index => $conversation) {
            if (! is_array($conversation)) {
                continue;
            }
            $messages = [];
            if (isset($conversation['mapping'])) {
                foreach ($conversation['mapping'] as $entry) {
                    $message = $entry['message'] ?? null;
                    if (! is_array($message) || ! in_array(data_get($message, 'author.role'), ['user', 'assistant'], true)) {
                        continue;
                    }
                    foreach (data_get($message, 'content.parts', []) as $part) {
                        if (is_string($part)) {
                            $messages[] = $part;
                        }
                    }
                }
            } else {
                foreach ($conversation['chat_messages'] ?? $conversation['messages'] ?? [] as $message) {
                    if (is_string($message['text'] ?? null)) {
                        $messages[] = $message['text'];
                    } elseif (is_string($message['content'] ?? null)) {
                        $messages[] = $message['content'];
                    } elseif (is_array($message['content'] ?? null)) {
                        foreach ($message['content'] as $part) {
                            if (is_string($part['text'] ?? null)) {
                                $messages[] = $part['text'];
                            }
                        }
                    }
                }
            }
            $body = $redactor->clean(mb_substr(implode("\n\n", $messages), 0, 100000));
            if ($body === '') {
                continue;
            }
            $result[] = ['index' => $index, 'title' => $redactor->clean(mb_substr((string) ($conversation['title'] ?? $conversation['name'] ?? 'Conversation'), 0, 180)),
                'body' => $body, 'source' => 'conversation-import:'.hash('sha256', $body)];
        }

        return $result;
    }
}
