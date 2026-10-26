            <aside class="ai-matches-chat">
        <div class="chat-panel">
            <div class="chat-head"><h2 class="h4 mb-1">Ask about these matches</h2><p class="small mb-0">Answers stay within this recommendation and Lance catalog data.</p></div>
            <div class="chat-log">
                @forelse($recommendation->messages as $message)
                    <div class="chat-message {{ $message->role }}"><strong>{{ $message->role === 'user' ? 'You' : 'FurniCraft' }}</strong>@if($message->role === 'assistant')<small class="d-block text-muted">{{ match($message->ai_source) { 'gemini' => 'Gemini response', 'catalog-rule' => 'Current catalog answer', 'local-fallback' => 'Local catalog fallback', default => $message->gemini_used ? 'AI-assisted response' : 'Local response' } }}</small>@endif<div>{{ $message->message }}</div></div>
                @empty
                    <p class="text-light-emphasis">Ask about material, finish, dimensions, price, or room suitability.</p>
                @endforelse
            </div>
            <form class="chat-compose" method="POST" action="{{ route('recommendations.chat',$recommendation) }}">
                @csrf
                <label class="visually-hidden" for="message">Question</label>
                <textarea class="form-control" id="message" name="message" rows="1" maxlength="1000" placeholder="Ask if a product or preference is available" required></textarea>
                <button class="btn btn-light w-100 mt-2">Ask FurniCraft</button>
            </form>
        </div>
            </aside>