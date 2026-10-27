<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatRequest;
use App\Http\Resources\AiChatResource;
use App\Services\PersonalDocumentAssistantService;

class AiChatController extends Controller
{
    public function __invoke(AiChatRequest $request, PersonalDocumentAssistantService $chat): AiChatResource
    {
        return new AiChatResource($chat->reply($request->user(), $request->validated('message'), $request->validated('locale', 'id'), $request->validated('context'), $request->validated('history', [])));
    }
}
