import type { ReactNode } from 'react';

export type LegalSection = {
  id: string;
  title: string;
  content: ReactNode;
};

export const sellerPlaceholder = 'Будет заполнено после регистрации продавца';

export default function LegalDocumentPage({
  eyebrow = 'Правовая информация',
  title,
  intro,
  notice,
  sections,
}: {
  eyebrow?: string;
  title: string;
  intro: string;
  notice?: ReactNode;
  sections: LegalSection[];
}) {
  return (
    <main className="min-h-screen bg-graphite-50 text-graphite-900 dark:bg-graphite-950 dark:text-white py-20 sm:py-24">
      <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <header className="max-w-3xl mb-8 sm:mb-10">
          <span className="text-sm font-semibold text-accent-600 dark:text-accent-500 uppercase tracking-widest">{eyebrow}</span>
          <h1 className="font-display font-extrabold text-3xl sm:text-5xl mt-2 tracking-tight">{title}</h1>
          <p className="text-graphite-600 dark:text-graphite-300 text-lg mt-4 leading-relaxed">{intro}</p>
        </header>

        {notice && (
          <div className="mb-8 p-5 sm:p-6 rounded-2xl border border-accent-200 dark:border-accent-500/20 bg-accent-50 dark:bg-accent-500/10 text-graphite-700 dark:text-graphite-200 leading-relaxed">
            {notice}
          </div>
        )}

        {sections.length > 3 && (
          <nav aria-label="Содержание документа" className="mb-8 p-5 sm:p-6 bg-white dark:bg-graphite-900 rounded-2xl border border-graphite-200 dark:border-white/5 shadow-sm">
            <h2 className="font-display font-bold text-lg mb-3">Содержание</h2>
            <div className="grid sm:grid-cols-2 gap-x-6 gap-y-2">
              {sections.map((section) => (
                <a key={section.id} href={`#${section.id}`} className="text-sm text-graphite-600 dark:text-graphite-400 hover:text-accent-600 dark:hover:text-accent-500 transition-colors">
                  {section.title}
                </a>
              ))}
            </div>
          </nav>
        )}

        <div className="space-y-5">
          {sections.map((section) => (
            <section key={section.id} id={section.id} className="scroll-mt-24 p-6 sm:p-8 bg-white dark:bg-graphite-900 rounded-3xl border border-graphite-200 dark:border-white/5 shadow-sm">
              <h2 className="font-display font-bold text-xl sm:text-2xl mb-3">{section.title}</h2>
              <div className="text-graphite-600 dark:text-graphite-400 leading-relaxed space-y-3">{section.content}</div>
            </section>
          ))}
        </div>
      </div>
    </main>
  );
}
