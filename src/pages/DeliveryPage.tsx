export default function DeliveryPage() {
  return (
    <main className="min-h-screen bg-graphite-50 dark:bg-graphite-950 text-graphite-900 dark:text-white py-20 sm:py-28">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">

        <div className="text-center mb-14">
          <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
            TELVORA
          </span>

          <h1 className="font-display font-extrabold text-4xl sm:text-5xl mt-3 tracking-tight">
            Доставка
          </h1>

          <p className="text-graphite-600 dark:text-graphite-300 text-lg mt-5 max-w-2xl mx-auto">
            Мы стремимся сделать получение заказа TELVORA максимально
            удобным и понятным.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-6">

          {/* Доставка заказа */}
          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5">
            <h2 className="font-display font-bold text-2xl mb-3">
              Доставка заказа
            </h2>

            <p className="text-graphite-600 dark:text-graphite-300 leading-relaxed">
              Способ и условия доставки определяются при оформлении заказа
              с учётом выбранного товара и адреса получения.
            </p>
          </div>

          {/* Получение товара */}
          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5">
            <h2 className="font-display font-bold text-2xl mb-3">
              Получение товара
            </h2>

            <p className="text-graphite-600 dark:text-graphite-300 leading-relaxed">
              Перед получением заказа рекомендуем проверить целостность
              упаковки и соответствие товара вашему заказу.
            </p>
          </div>

          {/* Подъём и установка */}
          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5">
            <h2 className="font-display font-bold text-2xl mb-3">
              Подъём и установка
            </h2>

            <p className="text-graphite-600 dark:text-graphite-300 leading-relaxed">
              Дополнительные услуги, включая подъём и установку телевизора,
              могут предоставляться отдельно в зависимости от условий заказа.
            </p>
          </div>

          {/* Важная информация */}
          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5">
            <h2 className="font-display font-bold text-2xl mb-3">
              Важная информация
            </h2>

            <p className="text-graphite-600 dark:text-graphite-300 leading-relaxed">
              Точные сроки, стоимость и доступные способы доставки будут
              указаны при оформлении заказа после определения условий продаж.
            </p>
          </div>

        </div>
      </div>
    </main>
  );
}