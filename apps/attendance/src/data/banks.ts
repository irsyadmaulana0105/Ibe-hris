/**
 * Daftar bank Indonesia populer untuk dropdown pilihan rekening.
 * Source: Bank Indonesia (BI) bank code daftar publik.
 * Note: format validation TIDAK definitif per bank (aturan bisa berubah);
 * kita pakai regex umum 6-30 digit untuk semua, lebih flexible.
 */
export interface BankOption {
  code: string         // BI bank code (3 digit), info only
  name: string         // Display name
  digits?: [number, number]  // [min, max] account number digit count (optional hint)
}

export const INDONESIAN_BANKS: BankOption[] = [
  // === Bank BUMN ===
  { code: '014', name: 'BCA',             digits: [10, 10] },
  { code: '008', name: 'Mandiri',         digits: [13, 13] },
  { code: '002', name: 'BRI',             digits: [15, 15] },
  { code: '009', name: 'BNI',             digits: [10, 10] },
  { code: '200', name: 'BTN',             digits: [16, 16] },
  { code: '451', name: 'BSI (Bank Syariah Indonesia)', digits: [10, 10] },

  // === Bank Swasta Nasional ===
  { code: '022', name: 'CIMB Niaga',       digits: [13, 13] },
  { code: '013', name: 'Permata',          digits: [10, 16] },
  { code: '011', name: 'Danamon',          digits: [10, 10] },
  { code: '028', name: 'OCBC NISP',        digits: [12, 12] },
  { code: '016', name: 'Maybank',          digits: [10, 10] },
  { code: '019', name: 'Panin',            digits: [10, 10] },
  { code: '426', name: 'Mega',             digits: [15, 15] },
  { code: '213', name: 'BTPN',             digits: [10, 12] },
  { code: '213', name: 'Jenius (BTPN)',    digits: [10, 10] },

  // === Bank Digital ===
  { code: '542', name: 'Bank Jago',        digits: [10, 10] },
  { code: '490', name: 'Bank Neo Commerce', digits: [10, 10] },
  { code: '567', name: 'Allo Bank',        digits: [10, 12] },
  { code: '535', name: 'SeaBank',          digits: [10, 16] },
  { code: '947', name: 'Bank Aladin',      digits: [10, 12] },

  // === Bank Asing ===
  { code: '046', name: 'DBS Indonesia',    digits: [10, 12] },
  { code: '023', name: 'Bank UOB',         digits: [10, 12] },
  { code: '950', name: 'Standard Chartered', digits: [10, 12] },
  { code: '031', name: 'Citibank',         digits: [10, 12] },
  { code: '054', name: 'Bank HSBC',        digits: [10, 12] },

  // === Lainnya / "Bank lainnya" untuk input bebas ===
]

/**
 * Validate format nomor rekening. Tidak strict per-bank - generic 6-30 digit.
 * Return null kalau valid, atau error message kalau tidak.
 */
export function validateBankAccount(bankName: string, accountNumber: string): string | null {
  const cleaned = accountNumber.replace(/[\s-]/g, '')
  if (!/^\d+$/.test(cleaned)) return 'Nomor rekening hanya boleh angka (tanpa spasi/strip).'
  if (cleaned.length < 6) return 'Nomor rekening terlalu pendek (min 6 digit).'
  if (cleaned.length > 30) return 'Nomor rekening terlalu panjang (max 30 digit).'

  // Hint per-bank (advisory only - boleh dilanjut kalau user yakin)
  const bank = INDONESIAN_BANKS.find((b) => b.name === bankName)
  if (bank?.digits) {
    const [min, max] = bank.digits
    if (cleaned.length < min || cleaned.length > max) {
      return `Nomor rekening ${bank.name} biasanya ${min === max ? min : `${min}-${max}`} digit. Pastikan sudah benar.`
    }
  }
  return null
}
