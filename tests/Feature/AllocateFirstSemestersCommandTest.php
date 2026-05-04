<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\CourseInformation;
use App\Models\Room;
use App\Services\HistoricalEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class AllocateFirstSemestersCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_shows_help_information_correctly()
    {
        $this->artisan('alocacao:allocate-first-semesters --help')
            ->assertSuccessful();
    }

    /** @test */
    public function it_runs_successfully_with_recalculate_historical_average_flag()
    {
        $historicalServiceMock = Mockery::mock(HistoricalEnrollmentService::class);
        $historicalServiceMock->shouldReceive('applyToSchoolClass')
            ->andReturn(true);
        $this->app->instance(HistoricalEnrollmentService::class, $historicalServiceMock);

        $previousTerm = SchoolTerm::factory()->create([
            'year' => 2024,
            'period' => '1° Semestre',
        ]);
        $currentTerm = SchoolTerm::factory()->create([
            'year' => 2025,
            'period' => '1° Semestre',
        ]);

        $room = Room::factory()->create();

        $previousClass = SchoolClass::factory()->undergraduate()->firstSemester()->create([
            'school_term_id' => $previousTerm->id,
            'codtur' => '2024101',
            'coddis' => 'MAT2453',
            'room_id' => $room->id,
        ]);

        $currentClass = SchoolClass::factory()->undergraduate()->firstSemester()->withoutRoom()->create([
            'school_term_id' => $currentTerm->id,
            'codtur' => '2025101',
            'coddis' => 'MAT2453',
        ]);

        $courseInfo = CourseInformation::create([
            'nomcur' => 'Matemática',
            'codcur' => '45031',
            'numsemidl' => 1,
            'perhab' => 'integral',
            'codhab' => '1',
            'nomhab' => 'Bacharelado',
            'tipobg' => 'O',
        ]);
        $currentClass->courseinformations()->attach($courseInfo);
        $previousClass->courseinformations()->attach($courseInfo);

        $this->artisan('alocacao:allocate-first-semesters --dry-run --recalculate-historical-average --force')
            ->assertSuccessful();
    }

    /** @test */
    public function it_runs_successfully_without_recalculate_flag()
    {
        $previousTerm = SchoolTerm::factory()->create([
            'year' => 2024,
            'period' => '1° Semestre',
        ]);
        $currentTerm = SchoolTerm::factory()->create([
            'year' => 2025,
            'period' => '1° Semestre',
        ]);

        $currentClass = SchoolClass::factory()->undergraduate()->firstSemester()->withoutRoom()->create([
            'school_term_id' => $currentTerm->id,
            'codtur' => '2025101',
            'coddis' => 'MAT2453',
        ]);

        $courseInfo = CourseInformation::create([
            'nomcur' => 'Matemática',
            'codcur' => '45031',
            'numsemidl' => 1,
            'perhab' => 'integral',
            'codhab' => '1',
            'nomhab' => 'Bacharelado',
            'tipobg' => 'O',
        ]);
        $currentClass->courseinformations()->attach($courseInfo);

        $this->artisan('alocacao:allocate-first-semesters --dry-run --force')
            ->assertSuccessful();
    }

    /** @test */
    public function it_does_not_mutate_database_in_dry_run_mode()
    {
        $historicalServiceMock = Mockery::mock(HistoricalEnrollmentService::class);
        $historicalServiceMock->shouldReceive('applyToSchoolClass')
            ->andReturn(true);
        $this->app->instance(HistoricalEnrollmentService::class, $historicalServiceMock);

        $previousTerm = SchoolTerm::factory()->create([
            'year' => 2024,
            'period' => '1° Semestre',
        ]);
        $currentTerm = SchoolTerm::factory()->create([
            'year' => 2025,
            'period' => '1° Semestre',
        ]);

        $room = Room::factory()->create();

        $previousClass = SchoolClass::factory()->undergraduate()->firstSemester()->create([
            'school_term_id' => $previousTerm->id,
            'codtur' => '2024101',
            'coddis' => 'MAT2453',
            'room_id' => $room->id,
        ]);

        $currentClass = SchoolClass::factory()->undergraduate()->firstSemester()->withoutRoom()->create([
            'school_term_id' => $currentTerm->id,
            'codtur' => '2025101',
            'coddis' => 'MAT2453',
            'estmtr' => 100,
        ]);

        $courseInfo = CourseInformation::create([
            'nomcur' => 'Matemática',
            'codcur' => '45031',
            'numsemidl' => 1,
            'perhab' => 'integral',
            'codhab' => '1',
            'nomhab' => 'Bacharelado',
            'tipobg' => 'O',
        ]);
        $currentClass->courseinformations()->attach($courseInfo);
        $previousClass->courseinformations()->attach($courseInfo);

        $this->artisan('alocacao:allocate-first-semesters --dry-run --recalculate-historical-average --force')
            ->assertSuccessful();

        $currentClass->refresh();
        $this->assertNull($currentClass->room_id);
        $this->assertEquals(100, $currentClass->estmtr);
        $this->assertNull($currentClass->historical_avg_applied_at);
    }
}
