import { Link } from 'react-router-dom';
import { Tv } from 'lucide-react';

export default function NotFoundPage() {
  return (
    <div className="min-h-screen flex flex-col items-center justify-center bg-white dark:bg-graphite-900 px-4 text-center">
      <div className="w-16 h-16 rounded-2xl bg-gradient-to-br from-accent-500 to-accent-700 flex items-center justify-center mb-6">
        <Tv className="w-8 h-8 text-white" strokeWidth={2.5} />
      </div>
      <h1 className="font-display font-extrabold text-6xl text-white mb-4">404</h1>
      <p className="text-graphite-400 text-lg mb-8 max-w-md">
        Страница не найдена. Возможно, она была перемещена или больше не существует.
      </p>
      <Link
        to="/"
        className="px-6 py-3 bg-accent-500 hover:bg-accent-600 text-white font-semibold rounded-xl transition-colors"
      >
        На главную
      </Link>
    </div>
  );
}
