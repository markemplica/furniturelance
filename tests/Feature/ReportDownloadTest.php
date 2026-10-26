<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_download_filtered_csv_and_pdf_reports(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        User::factory()->create(['name' => 'Included Customer', 'created_at' => '2026-10-20']);
        User::factory()->create(['name' => 'Excluded Customer', 'created_at' => '2025-01-01']);
        User::factory()->create(['name' => 'Another Customer', 'created_at' => '2026-10-20']);
        $filters = ['report' => 'customers', 'date_from' => '2026-10-01', 'q' => 'Included'];
        $this->actingAs($admin)->get(route('admin.reports.index', $filters))
            ->assertOk()->assertSee('Download')->assertSee('PDF (.pdf)');
        $csv = $this->get(route('admin.reports.export', $filters + ['format' => 'csv']))->assertOk();
        $this->assertStringContainsString('Included Customer', $csv->streamedContent());
        $this->assertStringNotContainsString('Excluded Customer', $csv->streamedContent());
        $this->assertStringNotContainsString('Another Customer', $csv->streamedContent());
        $this->get(route('admin.reports.index', $filters))->assertSee('Included Customer')->assertDontSee('Another Customer');
        foreach (['customers', 'reservations', 'recommendations', 'furniture'] as $report) {
            $response = $this->get(route('admin.reports.export', array_merge($filters, ['report' => $report, 'format' => 'pdf'])))
                ->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', $response->getContent());
            $this->assertStringContainsString('.pdf', $response->headers->get('Content-Disposition'));
        }
        $this->getJson(route('admin.reports.export', ['format' => 'invalid']))->assertUnprocessable();
        $this->actingAs(User::factory()->create())->get(route('admin.reports.export', ['format' => 'pdf']))->assertForbidden();
    }
}
