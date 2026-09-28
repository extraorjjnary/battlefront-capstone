<?php

namespace App\Enums;

enum RecommendationIntendedUse: string
{
    case GeneralUse = 'general_use';
    case OfficeWork = 'office_work';
    case Gaming = 'gaming';
    case NetworkingPisoWifi = 'networking_piso_wifi';
    case ContentCreation = 'content_creation';
    case Streaming = 'streaming';
    case HomeSecurity = 'home_security';
    case BusinessEnterprise = 'business_enterprise';

    /**
     * Names are resolved against current catalog records, never fixed database IDs.
     *
     * @return array{categories: list<string>, tags: list<string>}
     */
    public function catalogSignals(): array
    {
        return match ($this) {
            self::GeneralUse => ['categories' => [], 'tags' => ['Home Use']],
            self::OfficeWork => ['categories' => [], 'tags' => ['Office Use', 'Productivity']],
            self::Gaming => ['categories' => [], 'tags' => ['Gaming']],
            self::NetworkingPisoWifi => [
                'categories' => ['Networking', 'Vending & Coin-Op Machine Parts'],
                'tags' => [],
            ],
            self::ContentCreation => ['categories' => [], 'tags' => ['Content Creation']],
            self::Streaming => ['categories' => [], 'tags' => ['Streaming']],
            self::HomeSecurity => [
                'categories' => ['CCTV & Security'],
                'tags' => ['Home Security'],
            ],
            self::BusinessEnterprise => ['categories' => [], 'tags' => ['Business/Enterprise']],
        };
    }
}
