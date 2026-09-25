/**
 * Anteprima piccola di una foto scelta dalla galleria. Decodificare trenta
 * foto da 12 megapixel a piena risoluzione per mostrarle a 100px esaurisce
 * la memoria di un telefono: si ridimensiona con createImageBitmap (che
 * applica anche la rotazione EXIF) e si tiene solo un JPEG minuscolo.
 */
export async function createPreview(file: File, width = 240): Promise<string> {
  try {
    const bitmap = await createImageBitmap(file, { resizeWidth: width, resizeQuality: 'medium' })
    const canvas = document.createElement('canvas')

    canvas.width = bitmap.width
    canvas.height = bitmap.height
    canvas.getContext('2d')?.drawImage(bitmap, 0, 0)
    bitmap.close()

    const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.7))

    if (blob) {
      return URL.createObjectURL(blob)
    }
  } catch {
    // Browser senza ridimensionamento in createImageBitmap: si usa il file.
  }

  return URL.createObjectURL(file)
}
