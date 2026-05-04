<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\HistoricalEnrollmentService;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;
use App\Models\CourseInformation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;

class HistoricalEnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_identifies_first_semester_undergraduate_mandatory_class()
    {
        $term = SchoolTerm::factory()->create(['year' => 2025, 'period' => '1° Semestre']);
        $class = SchoolClass::factory()->undergraduate()->firstSemester()->create([
            'school_term_id' => $term->id,
            'codtur' => '2025101',
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
        $class->courseinformations()->attach($courseInfo);

        $service = new HistoricalEnrollmentService();
        $this->assertTrue($service->isFirstSemesterClass($class));
    }

    /** @test */
    public function it_rejects_postgraduate_class()
    {
        $term = SchoolTerm::factory()->create(['year' => 2025, 'period' => '1° Semestre']);
        $class = SchoolClass::factory()->graduate()->firstSemester()->create([
            'school_term_id' => $term->id,
            'codtur' => '2025101',
        ]);

        $service = new HistoricalEnrollmentService();
        $this->assertFalse($service->isFirstSemesterClass($class));
    }

    /** @test */
    public function it_rejects_second_semester_class()
    {
        $term = SchoolTerm::factory()->create(['year' => 2025, 'period' => '2° Semestre']);
        $class = SchoolClass::factory()->undergraduate()->secondSemester()->create([
            'school_term_id' => $term->id,
            'codtur' => '2025201',
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
        $class->courseinformations()->attach($courseInfo);

        $service = new HistoricalEnrollmentService();
        $this->assertFalse($service->isFirstSemesterClass($class));
    }

    /** @test */
    public function it_rejects_non_mandatory_class()
    {
        $term = SchoolTerm::factory()->create(['year' => 2025, 'period' => '1° Semestre']);
        $class = SchoolClass::factory()->undergraduate()->firstSemester()->create([
            'school_term_id' => $term->id,
            'codtur' => '2025101',
        ]);

        $courseInfo = CourseInformation::create([
            'nomcur' => 'Matemática',
            'codcur' => '45031',
            'numsemidl' => 1,
            'perhab' => 'integral',
            'codhab' => '1',
            'nomhab' => 'Bacharelado',
            'tipobg' => 'E',
        ]);
        $class->courseinformations()->attach($courseInfo);

        $service = new HistoricalEnrollmentService();
        $this->assertFalse($service->isFirstSemesterClass($class));
    }

    /** @test */
    public function it_applies_historical_average_when_deviation_exceeds_threshold()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('calculateHistoricalAverage')->andReturn([
            'average' => 50.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        $class = SchoolClass::factory()->create(['estmtr' => 100]);

        $this->assertTrue($service->applyToSchoolClass($class, true));
        $this->assertEquals(50, $class->estmtr);
        $this->assertNotNull($class->historical_avg_applied_at);
        $this->assertIsArray($class->historical_avg_metadata);
        $this->assertEquals(50.0, $class->historical_avg_metadata['average']);
        $this->assertEquals(100.0, $class->historical_avg_metadata['deviation_percent']);
    }

    /** @test */
    public function it_does_not_apply_when_deviation_is_within_threshold()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('calculateHistoricalAverage')->andReturn([
            'average' => 100.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        $class = SchoolClass::factory()->create(['estmtr' => 103]);

        $this->assertFalse($service->applyToSchoolClass($class, true));
        $this->assertEquals(103, $class->estmtr);
    }

    /** @test */
    public function it_does_not_apply_when_insufficient_historical_samples()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('calculateHistoricalAverage')->andReturn([
            'average' => 50.0,
            'samples' => 1,
            'years' => [2024],
        ]);

        $class = SchoolClass::factory()->create(['estmtr' => 100]);

        $this->assertFalse($service->applyToSchoolClass($class, true));
        $this->assertEquals(100, $class->estmtr);
    }

    /** @test */
    public function it_respects_already_applied_timestamp_without_force()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);

        $class = SchoolClass::factory()->create([
            'estmtr' => 100,
            'historical_avg_applied_at' => now(),
        ]);

        $this->assertFalse($service->applyToSchoolClass($class, false));
    }

    /** @test */
    public function it_reapplies_with_force_flag_even_if_already_applied()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('calculateHistoricalAverage')->andReturn([
            'average' => 50.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        $class = SchoolClass::factory()->create([
            'estmtr' => 100,
            'historical_avg_applied_at' => now()->subDay(),
        ]);

        $this->assertTrue($service->applyToSchoolClass($class, true));
        $this->assertEquals(50, $class->estmtr);
    }

    /** @test */
    public function it_does_not_persist_in_dry_run_mode()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('calculateHistoricalAverage')->andReturn([
            'average' => 50.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        $class = SchoolClass::factory()->create(['estmtr' => 100]);
        $originalUpdatedAt = $class->updated_at;

        $this->assertTrue($service->applyToSchoolClass($class, true, true));
        $this->assertEquals(50, $class->estmtr);
        $this->assertNotNull($class->historical_avg_applied_at);

        $class->refresh();
        $this->assertEquals(100, $class->estmtr);
        $this->assertNull($class->historical_avg_applied_at);
    }
}
