<?php

return [
    'model' => env('GROQ_CHAT_MODEL', 'openai/gpt-oss-20b'),
    'connect_timeout' => (int) env('GROQ_CHAT_CONNECT_TIMEOUT', 3),
    'timeout' => (int) env('GROQ_CHAT_TIMEOUT', 15),
    'per_minute' => min(60, max(1, (int) env('AI_CHAT_PER_MINUTE', 10))),
];
