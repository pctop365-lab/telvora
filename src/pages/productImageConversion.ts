const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
const MAX_IMAGE_DIMENSION = 10_000;
const MAX_IMAGE_PIXELS = 40_000_000;

export const isAvifImage = (file: File) =>
  file.type.toLowerCase() === 'image/avif' || /\.avif$/i.test(file.name);

export async function convertProductImageForUpload(file: File): Promise<File> {
  if (!isAvifImage(file)) return file;

  let imageBitmap: ImageBitmap;
  try {
    imageBitmap = await createImageBitmap(file);
  } catch {
    throw new Error('Браузер не смог преобразовать AVIF. Попробуйте другое изображение или формат WebP/JPG.');
  }

  try {
    const { width, height } = imageBitmap;
    if (width <= 0 || height <= 0 || width > MAX_IMAGE_DIMENSION || height > MAX_IMAGE_DIMENSION || width * height > MAX_IMAGE_PIXELS) {
      throw new Error('Размеры AVIF слишком велики для безопасного преобразования.');
    }
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d');
    if (!context) throw new Error('Не удалось подготовить AVIF к преобразованию.');
    context.drawImage(imageBitmap, 0, 0);
    const webpBlob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/webp', 0.9));
    if (!webpBlob || webpBlob.size <= 0) throw new Error('Не удалось преобразовать AVIF в WebP.');
    if (webpBlob.type.toLowerCase() !== 'image/webp') throw new Error('Браузер не смог создать WebP. Попробуйте другой браузер или изображение WebP/JPG.');
    if (webpBlob.size > MAX_IMAGE_BYTES) throw new Error('После преобразования изображение превышает 8 МБ.');
    return new File([webpBlob], `${file.name.replace(/\.avif$/i, '')}.webp`, { type: 'image/webp', lastModified: file.lastModified || Date.now() });
  } finally {
    imageBitmap.close();
  }
}
