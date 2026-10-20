<?php

namespace App\Services;

use Illuminate\Support\Str;
use LogicException;

class ModuleRouteManifest
{
    public function moduleForUri(string $uri): ?string
    {
        $matches = [];
        $knownModules = config('saas_modules.modules', []);

        foreach (config('saas_module_routes', []) as $module => $patterns) {
            if (! array_key_exists($module, $knownModules)) {
                throw new LogicException("Unknown SaaS module [{$module}] in route manifest.");
            }

            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $uri)) {
                    $matches[$module] = true;
                    break;
                }
            }
        }

        if (count($matches) > 1) {
            throw new LogicException(sprintf(
                'Route [%s] belongs to multiple SaaS modules: %s.',
                $uri,
                implode(', ', array_keys($matches)),
            ));
        }

        return array_key_first($matches);
    }
}
