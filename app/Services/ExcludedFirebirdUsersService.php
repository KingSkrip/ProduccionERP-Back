<?php

namespace App\Services;

class ExcludedFirebirdUsersService
{
    /**
     * IDs de USUARIOS (Firebird) a excluir por duplicados u otras razones.
     */
    private array $excludedIds = [
        2,
        3,
        4,
        5,
        6,
        7,
        9,
        10,
        16,
        18,
        24,
        29,
        32,
        38,
        46,
        52,
        54,
        67,
        68,
        69,
        71,
        73,
        75,
        79,
        83,
        84,
        89,
        90,
        91,
        92,
        93,
        94,
        97,
        98,
        100,
    ];

    private array $excludedIdsProveedores = [
        1250,
        1251,
        1252,
        1253,
        1254,
        1255,
        1256,
        1257,
        1258,
        1259,
        1260,
        1261,
        1262,
        1263,
        1264,
        1265,
        1266,
        1267,
        1268,
        1269,
        1270,
        1271,
        1272

    ];


    public function getExcludedIdsProveedores(): array
    {
        return $this->excludedIdsProveedores;
    }

    public function isExcludedProveedores(int $userId): bool
    {
        return in_array($userId, $this->excludedIdsProveedores, strict: true);
    }

    public function getExcludedIds(): array
    {
        return $this->excludedIds;
    }

    public function isExcluded(int $userId): bool
    {
        return in_array($userId, $this->excludedIds, strict: true);
    }
}