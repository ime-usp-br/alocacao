<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Exception;

/**
 * Service for mapping Urano permissions to Salas categories
 *
 * Transformation logic that converts:
 * - Roles (ADM, OPR, USR, PORTARIA) → Category IDs
 * - Groups (RESERVA DE SALAS, ATAAD, etc.) → Category IDs
 * - Individual room authorizations → Category IDs
 */
class PermissionMapper
{
    /**
     * Role mapping constants
     */
    private const ADMIN_ROLES = ['ADM', 'OPR'];
    private const USER_ROLE = 'USR';
    private const PORTARIA_ROLE = 'PORTARIA';

    /**
     * Group mapping to category names
     */
    private const GROUP_TO_CATEGORY_MAP = [
        'RESERVA DE SALAS' => 'Padrão',
        'ATAAD' => 'Padrão',
        'ATAAC' => 'Padrão',
        'DIRETORIA' => 'Padrão',
        'MAC' => 'Padrão',
        'MAE' => 'Padrão',
        'MAP' => 'Padrão',
        'MAT' => 'Padrão',
        'GRADUAÇÃO' => 'Padrão',
        'PÓS-GRADUAÇÃO' => 'Padrão',
        'CULTURA E EXTENSÃO' => 'Padrão',
        'CAEM' => 'Padrão',
        'CEA' => 'Padrão',
        'BIBLIOTECA' => 'Padrão',
        'CCSL' => 'Padrão',
    ];

    private array $categoryCache = [];
    private ?SalasApiClient $apiClient = null;

    /**
     * Set API client for fetching categories
     *
     * @param SalasApiClient $apiClient
     */
    public function setApiClient(SalasApiClient $apiClient): void
    {
        $this->apiClient = $apiClient;
    }

    /**
     * Map Urano user permissions to Salas category IDs
     *
     * @param object $uranoUser User object from UranoPermissionService
     * @return array Array of category IDs
     */
    public function mapUserToCategories(object $uranoUser): array
    {
        $this->ensureCategoriesLoaded();

        $categoryIds = [];

        // Priority 1: Admin roles (ADM/OPR) get all categories
        if ($this->hasAdminRole($uranoUser->papeis)) {
            return $this->getAllCategoryIds();
        }

        // Priority 2: Map groups to categories
        if (!empty($uranoUser->grupos)) {
            $groupCategories = $this->mapGroupsToCategories($uranoUser->grupos);
            $categoryIds = array_merge($categoryIds, $groupCategories);
        }

        // Priority 3: If user has USR role but no groups, assign default category
        if ($this->hasRole($uranoUser->papeis, self::USER_ROLE) && empty($categoryIds)) {
            $defaultCategory = $this->getCategoryIdByName('Padrão');
            if ($defaultCategory) {
                $categoryIds[] = $defaultCategory;
            }
        }

        // Priority 4: Portaria role gets specific category
        if ($this->hasRole($uranoUser->papeis, self::PORTARIA_ROLE)) {
            $portariaCategory = $this->getCategoryIdByName('Padrão');
            if ($portariaCategory) {
                $categoryIds[] = $portariaCategory;
            }
        }

        // Remove duplicates and return
        return array_values(array_unique($categoryIds));
    }

    /**
     * Get all category IDs from Salas database
     *
     * @return array
     */
    public function getAllCategoryIds(): array
    {
        $this->ensureCategoriesLoaded();
        return array_column($this->categoryCache, 'id');
    }

    /**
     * Get category ID by name
     *
     * @param string $categoryName
     * @return int|null
     */
    public function getCategoryIdByName(string $categoryName): ?int
    {
        $this->ensureCategoriesLoaded();

        foreach ($this->categoryCache as $category) {
            if (strtolower($category['nome']) === strtolower($categoryName)) {
                return $category['id'];
            }
        }

        return null;
    }

    /**
     * Get category name by ID
     *
     * @param int $categoryId
     * @return string|null
     */
    public function getCategoryNameById(int $categoryId): ?string
    {
        $this->ensureCategoriesLoaded();

        foreach ($this->categoryCache as $category) {
            if ($category['id'] === $categoryId) {
                return $category['nome'];
            }
        }

        return null;
    }

    /**
     * Check if user has admin role (ADM or OPR)
     *
     * @param array $roles
     * @return bool
     */
    private function hasAdminRole(array $roles): bool
    {
        return !empty(array_intersect($roles, self::ADMIN_ROLES));
    }

    /**
     * Check if user has specific role
     *
     * @param array $roles
     * @param string $roleName
     * @return bool
     */
    private function hasRole(array $roles, string $roleName): bool
    {
        return in_array($roleName, $roles);
    }

    /**
     * Map Urano groups to Salas category IDs
     *
     * @param array $groups Group names from Urano
     * @return array Category IDs
     */
    private function mapGroupsToCategories(array $groups): array
    {
        $categoryIds = [];

        foreach ($groups as $groupName) {
            if (isset(self::GROUP_TO_CATEGORY_MAP[$groupName])) {
                $categoryName = self::GROUP_TO_CATEGORY_MAP[$groupName];
                $categoryId = $this->getCategoryIdByName($categoryName);

                if ($categoryId) {
                    $categoryIds[] = $categoryId;
                }
            }
        }

        return $categoryIds;
    }

    /**
     * Load categories from Salas database and cache them
     *
     * @throws Exception If categories cannot be loaded
     */
    private function ensureCategoriesLoaded(): void
    {
        if (!empty($this->categoryCache)) {
            return;
        }

        try {
            // Try to fetch categories via API first (for consistency)
            if ($this->apiClient) {
                $this->categoryCache = $this->loadCategoriesFromApi();
            } else {
                // Fallback to direct database query if API client not set
                $this->categoryCache = $this->loadCategoriesFromDatabase();
            }
        } catch (Exception $e) {
            throw new Exception("Failed to load categories from Salas: {$e->getMessage()}");
        }

        if (empty($this->categoryCache)) {
            throw new Exception("No categories found in Salas");
        }
    }

    /**
     * Load categories from Salas API
     *
     * @return array
     * @throws Exception
     */
    private function loadCategoriesFromApi(): array
    {
        $response = $this->apiClient->get('/api/v1/categorias');

        if (!isset($response['data']) || !is_array($response['data'])) {
            throw new Exception("Invalid API response for categories");
        }

        return array_map(function ($category) {
            return [
                'id' => $category['id'],
                'nome' => $category['nome'],
            ];
        }, $response['data']);
    }

    /**
     * Load categories directly from Salas database (fallback)
     *
     * @return array
     */
    private function loadCategoriesFromDatabase(): array
    {
        return DB::connection('mysql')
            ->table('categorias')
            ->select('id', 'nome')
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'nome' => $category->nome,
                ];
            })
            ->toArray();
    }

    /**
     * Generate human-readable mapping summary
     *
     * @param object $uranoUser
     * @param array $categoryIds
     * @return string
     */
    public function getMappingSummary(object $uranoUser, array $categoryIds): string
    {
        $categoryNames = array_map(function ($id) {
            return $this->getCategoryNameById($id) ?? "Unknown ($id)";
        }, $categoryIds);

        $roles = implode(' + ', $uranoUser->papeis) ?: 'None';
        $groups = implode(', ', array_slice($uranoUser->grupos, 0, 3)) . (count($uranoUser->grupos) > 3 ? '...' : '');
        $categories = implode(', ', $categoryNames);

        return "Roles: {$roles} | Groups: {$groups} → Categories: {$categories}";
    }

    /**
     * Validate that all category IDs exist in Salas
     *
     * @param array $categoryIds
     * @return bool
     */
    public function validateCategoryIds(array $categoryIds): bool
    {
        $this->ensureCategoriesLoaded();
        $validIds = array_column($this->categoryCache, 'id');

        foreach ($categoryIds as $categoryId) {
            if (!in_array($categoryId, $validIds)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get mapping statistics for reporting
     *
     * @param Collection $mappings Collection of mapping objects (not Urano users)
     * @return array
     */
    public function getMappingStatistics(Collection $mappings): array
    {
        $stats = [
            'total_users' => $mappings->count(),
            'admin_users' => 0,
            'regular_users' => 0,
            'users_without_permissions' => 0,
            'category_distribution' => [],
        ];

        foreach ($mappings as $mapping) {
            // Check if user has admin role
            if ($this->hasAdminRole($mapping->roles)) {
                $stats['admin_users']++;
            } else {
                $stats['regular_users']++;
            }

            // Check if user has no category assignments
            if (empty($mapping->category_ids)) {
                $stats['users_without_permissions']++;
            }

            // Count category distribution
            foreach ($mapping->category_ids as $categoryId) {
                $categoryName = $this->getCategoryNameById($categoryId);
                if (!isset($stats['category_distribution'][$categoryName])) {
                    $stats['category_distribution'][$categoryName] = 0;
                }
                $stats['category_distribution'][$categoryName]++;
            }
        }

        return $stats;
    }

    /**
     * Get all mapped categories with their details
     *
     * @return array
     */
    public function getAllCategories(): array
    {
        $this->ensureCategoriesLoaded();
        return $this->categoryCache;
    }

    /**
     * Clear category cache (useful for testing)
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->categoryCache = [];
    }
}
