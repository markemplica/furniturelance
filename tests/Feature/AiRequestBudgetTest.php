<?php

namespace Tests\Feature;

use App\Services\{GeminiService, AiRequestBudgetExceeded};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiRequestBudgetTest extends TestCase
{
    use RefreshDatabase;

    private function request(GeminiService $service, int $retries = 0): string
    {
        return (new \ReflectionMethod(GeminiService::class, 'request'))->invoke($service, [['text'=>'Test only']], true, ['type'=>'object'], 'budget_test', 5, $retries, 2);
    }

    public function test_schema_and_model_fallbacks_cannot_exceed_budget(): void
    {
        config(['services.gemini.key'=>'fake', 'services.gemini.model'=>'primary', 'services.gemini.fallback_model'=>'secondary']);
        Http::fakeSequence()->push([],400)->push([],503)->push(['candidates'=>[['content'=>['parts'=>[['text'=>'{}']]]]]]);
        $service=app(GeminiService::class);
        $service->limitProviderRequests(2);
        try { $this->request($service); $this->fail('Budget should stop fallback'); }
        catch (AiRequestBudgetExceeded $e) { $this->assertSame('ai_request_limit_reached', $e->getMessage()); }
        Http::assertSentCount(2);
        $this->assertSame(2, $service->providerCallCount());
        $service->clearProviderRequestLimit();
        $this->assertSame('{}', $this->request($service));
        $this->assertSame(3, $service->providerCallCount());
    }

    public function test_http_retry_attempts_are_counted_and_zero_budget_sends_nothing(): void
    {
        config(['services.gemini.key'=>'fake', 'services.gemini.model'=>'primary', 'services.gemini.fallback_model'=>null]);
        Http::fakeSequence()->push([],503)->push(['candidates'=>[['content'=>['parts'=>[['text'=>'{}']]]]]]);
        $service=app(GeminiService::class);
        $service->limitProviderRequests(2);
        $this->assertSame('{}', $this->request($service,2));
        Http::assertSentCount(2);
        $this->assertSame(2,$service->providerCallCount());
        $service->limitProviderRequests(0);
        try { $this->request($service); $this->fail('Zero budget must block requests'); }
        catch (AiRequestBudgetExceeded $e) { $this->assertSame(2,$service->providerCallCount()); }
        Http::assertSentCount(2);
    }
}
