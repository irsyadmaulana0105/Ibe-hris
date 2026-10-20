<?php

namespace App\Console\Commands;

use App\Marketing\Services\KampanyeService;
use Illuminate\Console\Command;

/**
 * Kirim email kampanye CRM yang mengantre. Jalan tiap 10 menit lewat penjadwal;
 * tiap putaran hanya mengirim sedikit (crm.kampanye.per_putaran) dan tidak
 * pernah melewati batas harian (crm.kampanye.batas_harian).
 */
class CrmKampanyeKirim extends Command
{
    protected $signature = 'crm:kampanye-kirim {--paksa : abaikan jendela jam kirim (batas harian tetap berlaku)}';

    protected $description = 'Kirim email kampanye CRM yang mengantre, dengan batas harian';

    public function handle(KampanyeService $kampanye): int
    {
        if (! $this->option('paksa') && ! $kampanye->dalamJendelaKirim()) {
            $this->line('Di luar jam kirim kampanye.');

            return self::SUCCESS;
        }

        [$terkirim, $gagal] = $kampanye->putaran((int) config('crm.kampanye.per_putaran'));
        $this->info("Kampanye: terkirim={$terkirim} gagal={$gagal} sisa_kuota={$kampanye->sisaKuotaHariIni()}");

        return self::SUCCESS;
    }
}
