export default function WarrantyPage() {
  return (
    <main className="min-h-screen bg-graphite-50 dark:bg-graphite-950 text-white py-20 sm:py-28">
      <div className="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-12">
          <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
            TELVORA
          </span>

          <h1 className="font-display font-extrabold text-4xl sm:text-5xl mt-3 tracking-tight">
            Официальная гарантия
          </h1>

          <p className="text-graphite-400 text-lg mt-5 max-w-2xl mx-auto">
            Гарантийное обслуживание продукции TELVORA осуществляется
            в соответствии с условиями официальной гарантии.
          </p>
        </div>

        <div className="grid gap-6">
          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-white/5">
            <h2 className="font-display font-bold text-2xl mb-3">
              Гарантийное обслуживание
            </h2>

            <p className="text-graphite-400 leading-relaxed">
              Мы стремимся обеспечить комфортное обслуживание наших клиентов
              после покупки. Условия, сроки и порядок гарантийного обслуживания
              определяются официальными гарантийными условиями для конкретного
              товара.
            </p>
          </div>

          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-white/5">
            <h2 className="font-display font-bold text-2xl mb-3">
              Если возникла проблема
            </h2>

            <p className="text-graphite-400 leading-relaxed">
              Сохраните документы о покупке и обратитесь в службу поддержки
              TELVORA. Специалисты помогут определить дальнейший порядок
              действий.
            </p>
          </div>

          <div className="p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-white/5">
            <h2 className="font-display font-bold text-2xl mb-3">
              Важная информация
            </h2>

            <p className="text-graphite-400 leading-relaxed">
              Перед покупкой рекомендуем ознакомиться с официальными условиями
              гарантии, указанными для выбранной модели товара.
            </p>
          </div>
        </div>
      </div>
    </main>
  );
}