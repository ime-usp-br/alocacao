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
            'codtur' => '2025141',
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
            'codtur' => '2025141',
        ]);

        $service = new HistoricalEnrollmentService();
        $this->assertFalse($service->isFirstSemesterClass($class));
    }

    /** @test */
    public function it_rejects_non_ime_class_with_suffix_below_40()
    {
        $term = SchoolTerm::factory()->create(['year' => 2025, 'period' => '1° Semestre']);
        $class = SchoolClass::factory()->undergraduate()->firstSemester()->create([
            'school_term_id' => $term->id,
            'codtur' => '2025105', // sufixo 05 < 40
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
    public function it_rejects_second_semester_class()
    {
        $term = SchoolTerm::factory()->create(['year' => 2025, 'period' => '2° Semestre']);
        $class = SchoolClass::factory()->undergraduate()->secondSemester()->create([
            'school_term_id' => $term->id,
            'codtur' => '2025241',
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
            'codtur' => '2025141',
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
    public function it_applies_historical_average_when_subdimension_deviation_exceeds_threshold()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
        $service->shouldReceive('calculateHistoricalAverage')->andReturn([
            'average' => 50.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        // Subdimensionado: 40 inscritos vs média 50 (desvio = 20%)
        $class = SchoolClass::factory()->create(['estmtr' => 40]);

        $this->assertTrue($service->applyToSchoolClass($class, true));
        $this->assertEquals(50, $class->estmtr);
        $this->assertNotNull($class->historical_avg_applied_at);
        $this->assertIsArray($class->historical_avg_metadata);
        $this->assertEquals(50.0, $class->historical_avg_metadata['average']);
        $this->assertEquals(20.0, $class->historical_avg_metadata['deviation_percent']);
    }

    /** @test */
    public function it_does_not_apply_when_superdimension_deviation_exceeds_threshold()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
        $service->shouldReceive('calculateHistoricalAverage')->andReturn([
            'average' => 50.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        // Superdimensionado: 100 inscritos vs média 50 — não deve ser corrigido
        $class = SchoolClass::factory()->create(['estmtr' => 100]);

        $this->assertFalse($service->applyToSchoolClass($class, true));
        $this->assertEquals(100, $class->estmtr);
    }

    /** @test */
    public function it_does_not_apply_when_deviation_is_within_threshold()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
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
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
        $service->shouldReceive('calculateHistoricalAverage')->andReturn([
            'average' => 50.0,
            'samples' => 1,
            'years' => [2024],
        ]);

        // Subdimensionado, mas amostras insuficientes
        $class = SchoolClass::factory()->create(['estmtr' => 40]);

        $this->assertFalse($service->applyToSchoolClass($class, true));
        $this->assertEquals(40, $class->estmtr);
    }

    /** @test */
    public function it_respects_already_applied_timestamp_without_force()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);

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
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
        $service->shouldReceive('calculateHistoricalAverage')->andReturn([
            'average' => 50.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        $class = SchoolClass::factory()->create([
            'estmtr' => 40,
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
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
        $service->shouldReceive('calculateHistoricalAverage')->andReturn([
            'average' => 50.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        $class = SchoolClass::factory()->create(['estmtr' => 40]);
        $originalUpdatedAt = $class->updated_at;

        $this->assertTrue($service->applyToSchoolClass($class, true, true));
        $this->assertEquals(50, $class->estmtr);
        $this->assertNotNull($class->historical_avg_applied_at);

        $class->refresh();
        $this->assertEquals(40, $class->estmtr);
        $this->assertNull($class->historical_avg_applied_at);
    }

    /** @test */
    public function it_calculates_adjusted_demand_using_average_plus_stddev()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
        $service->shouldReceive('calculateHistoricalStats')->andReturn([
            'average' => 50.0,
            'stddev' => 10.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        // Subdimensionado: 30 inscritos vs média 50 (desvio = 40%)
        $class = SchoolClass::factory()->create(['estmtr' => 30]);

        $result = $service->calculateAdjustedDemand($class);

        $this->assertTrue($result['applied']);
        $this->assertEquals(80, $result['demand']); // 50 + 3 * 10
        $this->assertNotNull($result['metadata']);
        $this->assertEquals(50.0, $result['metadata']['average']);
        $this->assertEquals(10.0, $result['metadata']['stddev']);

        // Banco não deve ser alterado
        $class->refresh();
        $this->assertEquals(30, $class->estmtr);
    }

    /** @test */
    public function it_applies_cap_to_adjusted_demand()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
        $service->shouldReceive('calculateHistoricalStats')->andReturn([
            'average' => 60.0,
            'stddev' => 10.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        // Força o cap via reflection, já que o construtor já leu o padrão 100
        $reflection = new \ReflectionClass(HistoricalEnrollmentService::class);
        $capProperty = $reflection->getProperty('cap');
        $capProperty->setAccessible(true);
        $capProperty->setValue($service, 70);

        $class = SchoolClass::factory()->create(['estmtr' => 10]);

        $result = $service->calculateAdjustedDemand($class);

        $this->assertTrue($result['applied']);
        $this->assertEquals(70, $result['demand']); // 60 + 3*10 = 90, mas cap = 70
    }

    /** @test */
    public function it_does_not_adjust_demand_when_deviation_is_within_threshold()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
        $service->shouldReceive('calculateHistoricalStats')->andReturn([
            'average' => 100.0,
            'stddev' => 5.0,
            'samples' => 3,
            'years' => [2022, 2023, 2024],
        ]);

        // 95 inscritos vs média 100 = 5% de desvio, abaixo do threshold padrão 7%
        $class = SchoolClass::factory()->create(['estmtr' => 95]);

        $result = $service->calculateAdjustedDemand($class);

        $this->assertFalse($result['applied']);
        $this->assertEquals(95, $result['demand']);
    }

    /** @test */
    public function it_does_not_adjust_demand_for_non_first_semester_class()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(false);

        $class = SchoolClass::factory()->create(['estmtr' => 10]);

        $result = $service->calculateAdjustedDemand($class);

        $this->assertFalse($result['applied']);
        $this->assertEquals(10, $result['demand']);
    }

    /** @test */
    public function it_does_not_adjust_demand_when_insufficient_historical_samples()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
        $service->shouldReceive('calculateHistoricalStats')->andReturn([
            'average' => 50.0,
            'stddev' => 10.0,
            'samples' => 1,
            'years' => [2024],
        ]);

        $class = SchoolClass::factory()->create(['estmtr' => 10]);

        $result = $service->calculateAdjustedDemand($class);

        $this->assertFalse($result['applied']);
        $this->assertEquals(10, $result['demand']);
    }

    /** @test */
    public function it_uses_zero_stddev_when_only_one_sample_available()
    {
        $service = Mockery::mock(HistoricalEnrollmentService::class)->makePartial();
        $service->shouldReceive('isFirstSemesterClass')->andReturn(true);
        $service->shouldReceive('hasSpecialObstur')->andReturn(false);
        $service->shouldReceive('calculateHistoricalStats')->andReturn([
            'average' => 50.0,
            'stddev' => 0.0,
            'samples' => 2,
            'years' => [2023, 2024],
        ]);

        $class = SchoolClass::factory()->create(['estmtr' => 10]);

        $result = $service->calculateAdjustedDemand($class);

        $this->assertTrue($result['applied']);
        $this->assertEquals(50, $result['demand']); // 50 + 3*0
    }

    /** @test */
    public function it_accepts_config_overrides_in_constructor()
    {
        config(['alocacao.historical_threshold_percent' => 7.0]);
        config(['alocacao.historical_cap' => 100]);
        config(['alocacao.historical_lookback_years' => 5]);

        $service = new HistoricalEnrollmentService([
            'historical_threshold_percent' => 15.0,
            'historical_cap' => 80,
            'historical_lookback_years' => 3,
        ]);

        $reflection = new \ReflectionClass($service);

        $thresholdProperty = $reflection->getProperty('thresholdPercent');
        $thresholdProperty->setAccessible(true);
        $threshold = $thresholdProperty->getValue($service);

        $capProperty = $reflection->getProperty('cap');
        $capProperty->setAccessible(true);
        $cap = $capProperty->getValue($service);

        $lookbackProperty = $reflection->getProperty('yearsToLookBack');
        $lookbackProperty->setAccessible(true);
        $lookback = $lookbackProperty->getValue($service);

        $this->assertEquals(15.0, $threshold);
        $this->assertEquals(80, $cap);
        $this->assertEquals(3, $lookback);
    }

    /** @test */
    public function it_falls_back_to_config_when_overrides_are_missing()
    {
        config(['alocacao.historical_threshold_percent' => 12.0]);
        config(['alocacao.historical_cap' => 120]);

        $service = new HistoricalEnrollmentService([
            'historical_cap' => 90,
        ]);

        $reflection = new \ReflectionClass($service);

        $thresholdProperty = $reflection->getProperty('thresholdPercent');
        $thresholdProperty->setAccessible(true);
        $threshold = $thresholdProperty->getValue($service);

        $capProperty = $reflection->getProperty('cap');
        $capProperty->setAccessible(true);
        $cap = $capProperty->getValue($service);

        $this->assertEquals(12.0, $threshold);
        $this->assertEquals(90, $cap);
    }
}
