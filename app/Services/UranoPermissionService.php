<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Exception;

/**
 * Service for extracting permission data from Urano database
 *
 * Handles queries to legacy Urano permission system including:
 * - USUARIO table (active users)
 * - PAPEL table (roles: ADM, OPR, USR, PORTARIA)
 * - GRUPO table (organizational groups)
 * - SALA_GRUPOS_AUTORIZADORES (room authorizations by group)
 * - SALA_USUARIOS_AUTORIZADORES (room authorizations by user)
 */
class UranoPermissionService
{
    private string $connection = 'urano';

    /**
     * Get all users from Urano with their permissions (active and inactive)
     *
     * @return Collection Collection of users with roles and groups
     */
    public function getAllUsersWithPermissions(): Collection
    {
        return DB::connection($this->connection)
            ->table('USUARIO as u')
            ->select([
                'u.id',
                'u.codpes',
                'u.nompes',
                'u.email',
                'u.ativado',
                DB::raw('GROUP_CONCAT(DISTINCT p.nome ORDER BY p.id SEPARATOR ",") as papeis'),
                DB::raw('GROUP_CONCAT(DISTINCT p.id ORDER BY p.id SEPARATOR ",") as papel_ids'),
                DB::raw('GROUP_CONCAT(DISTINCT g.nome ORDER BY g.nome SEPARATOR "|") as grupos'),
                DB::raw('GROUP_CONCAT(DISTINCT g.id ORDER BY g.id SEPARATOR ",") as grupo_ids'),
            ])
            ->leftJoin('USUARIO_PAPEL as up', 'u.id', '=', 'up.usuario_id')
            ->leftJoin('PAPEL as p', 'up.papel_id', '=', 'p.id')
            ->leftJoin('USUARIO_GRUPO as ug', 'u.id', '=', 'ug.usuario_id')
            ->leftJoin('GRUPO as g', 'ug.grupo_id', '=', 'g.id')
            ->groupBy('u.id', 'u.codpes', 'u.nompes', 'u.email', 'u.ativado')
            ->get()
            ->map(function ($user) {
                // Parse comma/pipe-separated strings into arrays
                $user->papeis = $user->papeis ? explode(',', $user->papeis) : [];
                $user->papel_ids = $user->papel_ids ? array_map('intval', explode(',', $user->papel_ids)) : [];
                $user->grupos = $user->grupos ? explode('|', $user->grupos) : [];
                $user->grupo_ids = $user->grupo_ids ? array_map('intval', explode(',', $user->grupo_ids)) : [];

                return $user;
            });
    }

    /**
     * Get all active users from Urano with their permissions
     *
     * @deprecated Use getAllUsersWithPermissions() instead
     * @return Collection Collection of users with roles and groups
     */
    public function getActiveUsersWithPermissions(): Collection
    {
        return $this->getAllUsersWithPermissions()->where('ativado', 1)->values();
    }

    /**
     * Get individual room authorizations for a specific user
     *
     * @param int $uranoUserId Urano user ID
     * @return Collection Collection of authorized room IDs
     */
    public function getUserRoomAuthorizations(int $uranoUserId): Collection
    {
        return DB::connection($this->connection)
            ->table('SALA_USUARIOS_AUTORIZADORES')
            ->where('id_usuario', $uranoUserId)
            ->pluck('id_sala');
    }

    /**
     * Get room authorizations by group
     *
     * @param int $groupId Group ID
     * @return Collection Collection of authorized room IDs
     */
    public function getGroupRoomAuthorizations(int $groupId): Collection
    {
        return DB::connection($this->connection)
            ->table('SALA_GRUPOS_AUTORIZADORES')
            ->where('id_grupo', $groupId)
            ->pluck('id_sala');
    }

    /**
     * Get all roles (PAPEL) from Urano
     *
     * @return Collection
     */
    public function getAllRoles(): Collection
    {
        return DB::connection($this->connection)
            ->table('PAPEL')
            ->select('id', 'nome')
            ->get();
    }

    /**
     * Get all groups (GRUPO) from Urano
     *
     * @return Collection
     */
    public function getAllGroups(): Collection
    {
        return DB::connection($this->connection)
            ->table('GRUPO')
            ->select('id', 'nome')
            ->get();
    }

    /**
     * Get comprehensive permission summary for a single user
     *
     * @param int $codpes User's USP number
     * @return array Associative array with user permissions
     * @throws Exception If user not found
     */
    public function getUserPermissionSummary(int $codpes): array
    {
        $user = DB::connection($this->connection)
            ->table('USUARIO')
            ->where('codpes', $codpes)
            ->first();

        if (!$user) {
            throw new Exception("User with codpes {$codpes} not found in Urano");
        }

        // Get roles
        $roles = DB::connection($this->connection)
            ->table('USUARIO_PAPEL as up')
            ->join('PAPEL as p', 'up.papel_id', '=', 'p.id')
            ->where('up.usuario_id', $user->id)
            ->pluck('p.nome')
            ->toArray();

        // Get groups
        $groups = DB::connection($this->connection)
            ->table('USUARIO_GRUPO as ug')
            ->join('GRUPO as g', 'ug.grupo_id', '=', 'g.id')
            ->where('ug.usuario_id', $user->id)
            ->pluck('g.nome')
            ->toArray();

        // Get individual room authorizations
        $authorizedRooms = $this->getUserRoomAuthorizations($user->id)->toArray();

        return [
            'codpes' => $user->codpes,
            'name' => $user->nompes,
            'email' => $user->email,
            'roles' => $roles,
            'groups' => $groups,
            'authorized_rooms' => $authorizedRooms,
            'urano_user_id' => $user->id,
        ];
    }

    /**
     * Get statistics about Urano permissions
     *
     * @return array Summary statistics
     */
    public function getPermissionStatistics(): array
    {
        return [
            'total_users' => DB::connection($this->connection)->table('USUARIO')->count(),
            'active_users' => DB::connection($this->connection)->table('USUARIO')->where('ativado', 1)->count(),
            'total_roles' => DB::connection($this->connection)->table('PAPEL')->count(),
            'total_groups' => DB::connection($this->connection)->table('GRUPO')->count(),
            'user_role_assignments' => DB::connection($this->connection)->table('USUARIO_PAPEL')->count(),
            'user_group_assignments' => DB::connection($this->connection)->table('USUARIO_GRUPO')->count(),
            'room_group_authorizations' => DB::connection($this->connection)->table('SALA_GRUPOS_AUTORIZADORES')->count(),
            'room_user_authorizations' => DB::connection($this->connection)->table('SALA_USUARIOS_AUTORIZADORES')->count(),
        ];
    }

    /**
     * Verify Urano database connectivity
     *
     * @return bool True if connection successful
     */
    public function testConnection(): bool
    {
        try {
            DB::connection($this->connection)->getPdo();
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Get roles grouped by user count
     *
     * @return Collection
     */
    public function getRoleDistribution(): Collection
    {
        return DB::connection($this->connection)
            ->table('PAPEL as p')
            ->leftJoin('USUARIO_PAPEL as up', 'p.id', '=', 'up.papel_id')
            ->leftJoin('USUARIO as u', function($join) {
                $join->on('up.usuario_id', '=', 'u.id')
                     ->where('u.ativado', '=', 1);
            })
            ->select('p.nome as role_name', DB::raw('COUNT(DISTINCT u.id) as user_count'))
            ->groupBy('p.id', 'p.nome')
            ->orderBy('user_count', 'desc')
            ->get();
    }

    /**
     * Get groups grouped by user count
     *
     * @return Collection
     */
    public function getGroupDistribution(): Collection
    {
        return DB::connection($this->connection)
            ->table('GRUPO as g')
            ->leftJoin('USUARIO_GRUPO as ug', 'g.id', '=', 'ug.grupo_id')
            ->leftJoin('USUARIO as u', function($join) {
                $join->on('ug.usuario_id', '=', 'u.id')
                     ->where('u.ativado', '=', 1);
            })
            ->select('g.nome as group_name', DB::raw('COUNT(DISTINCT u.id) as user_count'))
            ->groupBy('g.id', 'g.nome')
            ->orderBy('user_count', 'desc')
            ->get();
    }
}
