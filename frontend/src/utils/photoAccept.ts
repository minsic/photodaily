/**
 * Formati accettati dai campi file delle foto. HEIC scritto esplicitamente:
 * con un generico "image/*" Safari su iPhone converte le foto in JPEG prima
 * di passarle alla pagina e nella conversione si può perdere la data di scatto.
 */
export const PHOTO_ACCEPT = 'image/jpeg,image/png,image/webp,image/heic,image/heif'
