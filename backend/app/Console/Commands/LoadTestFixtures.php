<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Location;
use App\Models\Screen;
use App\Models\ScreenGroup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('saie:loadtest-fixtures {count=100} {--output=storage/app/private/loadtest-tokens.json}')]
#[Description('Crée N écrans et lecteurs appairés pour un test de charge (R15) — base de test uniquement, jamais en production')]
class LoadTestFixtures extends Command
{
    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Interdit en production.');

            return self::FAILURE;
        }
        $locations = Location::query()->whereHas('zone', fn ($q) => $q->where('max_classification', 'interne'))->get();
        $group = ScreenGroup::query()->where('code', 'GRP-INTERNES')->first();
        $tokens = [];
        for ($i = 1; $i <= (int) $this->argument('count'); $i++) {
            $screen = Screen::query()->create(['code' => sprintf('CHG-%03d', $i), 'name' => "Écran de charge $i", 'kind' => 'display', 'location_id' => $locations[$i % $locations->count()]->id, 'orientation' => 'landscape', 'resolution' => '1920x1080', 'is_demo' => true]);
            $group?->screens()->attach($screen->id);
            $device = Device::query()->create(['code' => sprintf('LCT-CHG-%03d', $i), 'screen_id' => $screen->id, 'hardware_id' => "load-$i", 'status' => 'active', 'paired_at' => now(), 'is_demo' => true]);
            $tokens[] = $device->createToken('device', ['device'])->plainTextToken;
        }
        file_put_contents(base_path((string) $this->option('output')), json_encode($tokens));
        $this->info(count($tokens).' lecteurs de charge créés.');

        return self::SUCCESS;
    }
}
