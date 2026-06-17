<?php

namespace App\Services;

use App\Models\AllocationState;
use App\Models\SchoolClass;
use App\Models\SchoolTerm;

class AllocationStateService
{
    /**
     * Capture the current allocation state for a school term.
     *
     * @param SchoolTerm $term
     * @param string $name
     * @param int|null $solverLogId
     * @return AllocationState
     */
    public static function capture(SchoolTerm $term, string $name, ?int $solverLogId = null): AllocationState
    {
        $allocations = [];

        $schoolClasses = SchoolClass::whereBelongsTo($term)
            ->with(['room', 'fusion.master.room', 'fusion.schoolclasses.room'])
            ->get();

        foreach ($schoolClasses as $schoolClass) {
            $allocations[$schoolClass->id] = self::resolveRoomId($schoolClass);
        }

        return AllocationState::create([
            'school_term_id' => $term->id,
            'name' => $name,
            'allocations' => $allocations,
            'solver_log_id' => $solverLogId,
        ]);
    }

    /**
     * Restore a previously captured allocation state.
     *
     * @param AllocationState $state
     * @return array{missing: int, unassigned: int}
     */
    public static function restore(AllocationState $state): array
    {
        $term = SchoolTerm::getLatest();

        $savedAllocations = $state->allocations ?? [];
        $missingCount = 0;

        $currentClasses = SchoolClass::whereBelongsTo($term)
            ->with('fusion.master')
            ->get()
            ->keyBy('id');

        foreach ($savedAllocations as $schoolClassId => $roomId) {
            $schoolClass = $currentClasses->get($schoolClassId);

            if (! $schoolClass) {
                $missingCount++;
                continue;
            }

            if ($schoolClass->fusion_id && $schoolClass->fusion && $schoolClass->fusion->master) {
                $master = $schoolClass->fusion->master;
                $master->room_id = $roomId;
                $master->save();
            } else {
                $schoolClass->room_id = $roomId;
                $schoolClass->save();
            }
        }

        $unassignedCount = SchoolClass::whereBelongsTo($term)->whereNull('room_id')->count();

        return [
            'missing' => $missingCount,
            'unassigned' => $unassignedCount,
        ];
    }

    /**
     * Resolve the room id that should be saved for a school class.
     *
     * For fused classes, prefer the master's room. If the master has no room,
     * fall back to any child class that has a room.
     *
     * @param SchoolClass $schoolClass
     * @return int|null
     */
    private static function resolveRoomId(SchoolClass $schoolClass): ?int
    {
        if ($schoolClass->fusion_id && $schoolClass->fusion) {
            $master = $schoolClass->fusion->master;

            if ($master && $master->room_id) {
                return $master->room_id;
            }

            foreach ($schoolClass->fusion->schoolclasses as $child) {
                if ($child->room_id) {
                    return $child->room_id;
                }
            }

            return null;
        }

        return $schoolClass->room_id;
    }
}
