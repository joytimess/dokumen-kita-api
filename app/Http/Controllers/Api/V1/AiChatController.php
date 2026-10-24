<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatRequest;
use App\Http\Resources\AiChatResource;
use App\Services\SupportChatService;

class AiChatController extends Controller
{
    public function __invoke(AiChatRequest $request, SupportChatService $chat): AiChatResource
    {
        return new AiChatResource($chat->reply($request->validated('message'), $request->validated('locale', 'id')));
    }
}
