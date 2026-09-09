<?php

namespace Tests\Feature\Reports;

use App\Models\Client;
use App\Models\Project;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScopedReportTest extends TestCase
{
    use RefreshDatabase;

    private function seedOrg(): array
    {
        $org = $this->createOrganization('Agency');
        $this->bindOrg($org);

        $admin = $this->adminOf($org, ['name' => 'Admin']);
        $member = $this->memberOf($org, ['name' => 'Member']);

        $client = Client::factory()->create(['name' => 'ACME']);
        $project = Project::factory()->create(['client_id' => $client->id, 'name' => 'Website', 'hourly_rate' => 100]);
        $other = Project::factory()->create(['client_id' => $client->id, 'name' => 'App', 'hourly_rate' => 100]);

        // 2026-08-03 10:00 Berlin: 50 min billable by admin
        TimeEntry::factory()->create([
            'user_id' => $admin->id, 'project_id' => $project->id,
            'started_at' => '2026-08-03 08:00:00', 'stopped_at' => '2026-08-03 08:50:00',
            'duration_seconds' => 3000, 'is_billable' => true,
        ]);
        // 2026-08-10: 1h non-billable by member
        TimeEntry::factory()->create([
            'user_id' => $member->id, 'project_id' => $other->id,
            'started_at' => '2026-08-10 12:00:00', 'stopped_at' => '2026-08-10 13:00:00',
            'duration_seconds' => 3600, 'is_billable' => false,
        ]);
        // 2026-08-31 23:30 Berlin (= 21:30 UTC): still August in report tz
        TimeEntry::factory()->create([
            'user_id' => $admin->id, 'project_id' => $project->id,
            'started_at' => '2026-08-31 21:30:00', 'stopped_at' => '2026-08-31 22:00:00',
            'duration_seconds' => 1800, 'is_billable' => true,
        ]);
        // September entry, must be excluded
        TimeEntry::factory()->create([
            'user_id' => $admin->id, 'project_id' => $project->id,
            'started_at' => '2026-09-01 08:00:00', 'stopped_at' => '2026-09-01 09:00:00',
            'duration_seconds' => 3600, 'is_billable' => true,
        ]);

        return compact('org', 'admin', 'member', 'client', 'project', 'other');
    }

    public function test_client_report_aggregates_month_with_rounding(): void
    {
        ['org' => $org, 'admin' => $admin, 'client' => $client] = $this->seedOrg();

        $response = $this->actingInOrg($admin, $org)
            ->getJson("/api/reports/clients/{$client->id}?date_from=2026-08-01&date_to=2026-08-31&rounding=15&locale=de");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertSame('client', $data['scope']['type']);
        $this->assertSame('ACME', $data['scope']['name']);
        $this->assertSame('August 2026', $data['period']['label']);
        $this->assertTrue($data['period']['is_full_month']);

        // 50 min -> 60 min, 60 min stays, 30 min stays = 2.5 h total; billable 1.5 h; amount 150
        $this->assertSame(3, $data['totals']['entry_count']);
        $this->assertSame(9000, $data['totals']['total_seconds']);
        $this->assertSame(5400, $data['totals']['billable_seconds']);
        $this->assertEquals(150, $data['totals']['amount']);

        $this->assertCount(2, $data['by_project']);
        $this->assertSame('Website', $data['by_project'][0]['name']);
        $this->assertCount(2, $data['by_user']);
        $this->assertSame(['2026-08-03', '2026-08-10', '2026-08-31'], array_column($data['days'], 'date'));
    }

    public function test_project_and_user_reports_and_period_label(): void
    {
        ['org' => $org, 'admin' => $admin, 'member' => $member, 'project' => $project] = $this->seedOrg();

        $this->actingInOrg($admin, $org)
            ->getJson("/api/reports/projects/{$project->id}?date_from=2026-08-01&date_to=2026-08-15")
            ->assertOk()
            ->assertJsonPath('data.scope.type', 'project')
            ->assertJsonPath('data.scope.client_name', 'ACME')
            ->assertJsonPath('data.period.is_full_month', false)
            ->assertJsonPath('data.totals.entry_count', 1);

        $this->actingInOrg($admin, $org)
            ->getJson("/api/reports/users/{$member->id}?date_from=2026-08-01&date_to=2026-08-31")
            ->assertOk()
            ->assertJsonPath('data.totals.entry_count', 1)
            ->assertJsonPath('data.totals.billable_seconds', 0);
    }

    public function test_member_only_sees_own_entries_and_cannot_read_other_users(): void
    {
        ['org' => $org, 'admin' => $admin, 'member' => $member, 'client' => $client] = $this->seedOrg();

        $this->actingInOrg($member, $org)
            ->getJson("/api/reports/clients/{$client->id}?date_from=2026-08-01&date_to=2026-08-31")
            ->assertOk()
            ->assertJsonPath('data.totals.entry_count', 1);

        $this->actingInOrg($member, $org)
            ->getJson("/api/reports/users/{$admin->id}?date_from=2026-08-01&date_to=2026-08-31")
            ->assertForbidden();
    }

    public function test_reports_are_isolated_between_organizations(): void
    {
        ['client' => $client] = $this->seedOrg();

        $otherOrg = $this->createOrganization('Other');
        $outsider = $this->adminOf($otherOrg);

        $this->actingInOrg($outsider, $otherOrg)
            ->getJson("/api/reports/clients/{$client->id}?date_from=2026-08-01&date_to=2026-08-31")
            ->assertNotFound();

        $this->actingInOrg($outsider, $otherOrg)
            ->getJson('/api/reports/budget')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingInOrg($outsider, $otherOrg)
            ->getJson('/api/reports/utilization?date_from=2026-08-01&date_to=2026-08-31')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingInOrg($outsider, $otherOrg)
            ->getJson('/api/users')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_pdf_and_csv_formats(): void
    {
        ['org' => $org, 'admin' => $admin, 'client' => $client] = $this->seedOrg();

        $pdf = $this->actingInOrg($admin, $org)
            ->get("/api/reports/clients/{$client->id}?date_from=2026-08-01&date_to=2026-08-31&format=pdf&locale=de");
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringContainsString('agency-acme-2026-08.pdf', $pdf->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        $csv = $this->actingInOrg($admin, $org)
            ->get("/api/reports/clients/{$client->id}?date_from=2026-08-01&date_to=2026-08-31&format=csv");
        $csv->assertOk();
        $this->assertStringContainsString('Website', $csv->streamedContent());
    }

    public function test_summary_period_grouping_is_portable(): void
    {
        ['org' => $org, 'admin' => $admin] = $this->seedOrg();

        $this->actingInOrg($admin, $org)
            ->getJson('/api/reports/summary?date_from=2026-08-01&date_to=2026-09-30&group_by=month')
            ->assertOk()
            ->assertJsonPath('data.0.period', '2026-08')
            ->assertJsonPath('data.1.period', '2026-09')
            ->assertJsonPath('data.0.entry_count', 3);

        $this->actingInOrg($admin, $org)
            ->getJson('/api/reports/summary?date_from=2026-08-01&date_to=2026-08-31&group_by=week')
            ->assertOk()
            ->assertJsonPath('data.0.period', '2026-W32');
    }
}
