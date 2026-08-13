import { Cpu, Monitor, Sun, Volume2, Sparkles, Wifi } from 'lucide-react';
import { siteContent } from '@/data/siteContent';

const techFeatures = [
  {
    icon: Monitor,
    title: 'Self-Lit OLED',
    desc: 'Каждый пиксель светится самостоятельно. Идеальный чёрный, бесконечный контраст — без подсветки и засветки.',
  },
  {
    icon: Cpu,
    title: 'Нейросетевой процессор',
    desc: 'Процессор Telvora Neural X1 анализирует каждый кадр в реальном времени и улучшает резкость, цвет и детализацию.',
  },
  {
    icon: Sun,
    title: 'Dolby Vision IQ',
    desc: 'Датчик освещённости подстраивает HDR под условия в комнате. Идеальная картинка днём и ночью.',
  },
  {
    icon: Volume2,
    title: 'Dolby Atmos',
    desc: 'Объёмный звук кинематографического уровня. Встроенная аудиосистема с виртуальным 5.1.2 канальным звучанием.',
  },
  {
    icon: Wifi,
    title: 'Telvora OS 4.0',
    desc: 'Мгновенный запуск, все стриминговые сервисы, управление умным домом. Обновляется автоматически.',
  },
  {
    icon: Sparkles,
    title: 'Безрамочный дизайн',
    desc: 'Рамка всего 0.3 мм. Телевизор сливается со стеной — остаётся только изображение. Никаких компромиссов.',
  },
];

export default function TechSection() {
  const { cinemaImage, lifestyleImage } = siteContent.tech;

  return (
    <section id="tech" className="py-20 sm:py-28 bg-graphite-950 relative overflow-hidden">
      <div className="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-accent-500/5 rounded-full blur-3xl" />

      <div className="relative max-w-8xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center max-w-3xl mx-auto mb-16">
          <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
            Технологии
          </span>
          <h2 className="font-display font-extrabold text-4xl sm:text-5xl text-white mt-2 tracking-tight text-balance">
            Инженерия изображения
          </h2>
          <p className="text-graphite-400 mt-4 text-lg">
            Каждый TELVORA — это результат десятилетий исследований в области
            цветопередачи, контраста и звука.
          </p>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mb-20">
          {techFeatures.map((f, i) => (
            <div
              key={f.title}
              className="group p-7 bg-graphite-800/50 backdrop-blur-sm border border-white/5 rounded-3xl hover:border-white/10 hover:bg-graphite-800 transition-all animate-fade-up"
              style={{ animationDelay: `${i * 0.08}s`, opacity: 0 }}
            >
              <div className="w-12 h-12 rounded-2xl bg-gradient-to-br from-accent-500/20 to-accent-500/5 flex items-center justify-center mb-5 group-hover:scale-110 transition-transform">
                <f.icon className="w-6 h-6 text-accent-500" />
              </div>
              <h3 className="font-display font-bold text-lg text-white mb-2">{f.title}</h3>
              <p className="text-sm text-graphite-400 leading-relaxed">{f.desc}</p>
            </div>
          ))}
        </div>

        <div className="relative rounded-4xl overflow-hidden mb-6 group">
          <img
            src={cinemaImage}
            alt="Домашний кинотеатр TELVORA"
            className="w-full h-[400px] sm:h-[500px] object-cover group-hover:scale-105 transition-transform duration-700"
          />
          <div className="absolute inset-0 bg-gradient-to-r from-graphite-950 via-graphite-950/70 to-transparent" />
          <div className="absolute inset-0 flex items-center">
            <div className="max-w-xl p-8 sm:p-12">
              <span className="text-sm font-semibold text-accent-500 uppercase tracking-widest">
                Домашний кинотеатр
              </span>
              <h3 className="font-display font-extrabold text-3xl sm:text-4xl text-white mt-2 leading-tight">
                Кинотеатр у вас дома
              </h3>
              <p className="text-graphite-300 mt-4 text-lg">
                Dolby Vision + Dolby Atmos превращают любую комнату в премиальный
                кинозал. Почувствуйте каждый кадр.
              </p>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
          <div className="relative rounded-4xl overflow-hidden group">
            <img
              src={lifestyleImage}
              alt="TELVORA в интерьере"
              className="w-full h-[300px] object-cover group-hover:scale-105 transition-transform duration-700"
            />
            <div className="absolute inset-0 bg-gradient-to-t from-graphite-950 via-graphite-950/30 to-transparent" />
            <div className="absolute bottom-0 left-0 right-0 p-8">
              <h3 className="font-display font-bold text-2xl text-white">Дизайн без границ</h3>
              <p className="text-graphite-300 mt-2 text-sm">
                Безрамочный корпус, профиль 8.9 мм. Тоньше, чем кажется.
              </p>
            </div>
          </div>

          <div className="flex flex-col gap-6">
            <div className="flex-1 p-8 bg-gradient-to-br from-graphite-800 to-graphite-900 rounded-4xl border border-white/5 flex flex-col justify-center">
              <span className="text-5xl font-display font-extrabold text-accent-500">5 лет</span>
              <p className="text-white text-lg mt-2 font-semibold">Официальной гарантии</p>
              <p className="text-graphite-400 text-sm mt-1">
                Расширенная сервисная программа без скрытых условий.
              </p>
            </div>
            <div className="flex-1 p-8 bg-gradient-to-br from-accent-500/10 to-graphite-900 rounded-4xl border border-accent-500/10 flex flex-col justify-center">
              <span className="text-5xl font-display font-extrabold text-white">24/7</span>
              <p className="text-white text-lg mt-2 font-semibold">Поддержка клиентов</p>
              <p className="text-graphite-400 text-sm mt-1">
                Профессиональная помощь в любое время дня и ночи.
              </p>
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
