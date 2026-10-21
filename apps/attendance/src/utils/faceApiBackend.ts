import type * as FaceApi from '@vladmandic/face-api'

/**
 * Siapkan mesin hitung TensorFlow.js sebelum model wajah dipakai.
 *
 * Bundel face-api mendaftarkan tiga mesin: cpu (prioritas 1), webgl (2), dan wasm (2).
 * Bila WebGL tidak tersedia (GPU diblokir peramban, sesi desktop jarak jauh, mesin virtual),
 * TensorFlow memilih wasm yang inisialisasinya asinkron, lalu operasi pertama gagal dengan
 * "The highest priority backend 'wasm' has not yet been initialized".
 *
 * Karena itu mesin dipilih eksplisit dan ditunggu sampai siap: webgl dulu, lalu cpu.
 * wasm sengaja dilewati karena butuh berkas .wasm tambahan yang tidak ikut paket.
 */
export async function prepareFaceApiBackend(faceapi: typeof FaceApi): Promise<void> {
  // Deklarasi tipe `tf` bawaan face-api tidak memuat setBackend/ready, padahal keduanya ada saat berjalan.
  const tf = faceapi.tf as unknown as {
    setBackend(name: string): Promise<boolean>
    ready(): Promise<void>
  }

  for (const backend of ['webgl', 'cpu']) {
    try {
      if (await tf.setBackend(backend)) break
    } catch {
      // Mesin ini tidak bisa dipakai di peramban ini; coba yang berikutnya.
    }
  }

  await tf.ready()
}
