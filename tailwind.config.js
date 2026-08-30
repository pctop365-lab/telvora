/** @type {import('tailwindcss').Config} */
export default {
  darkMode: 'class',

  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],

  theme: {
    extend: {
      colors: {
        graphite: {
          50: '#F5F6F8',
          100: '#E9EBEF',
          200: '#D1D5DB',
          300: '#A1A7B0',
          400: '#6B7280',
          500: '#4B5563',
          600: '#374151',
          700: '#1F2329',
          800: '#15181C',
          900: '#0B0D10',
          950: '#06080A',
        },

        accent: {
          50: '#FFF3ED',
          100: '#FFE2D4',
          200: '#FFC5A8',
          300: '#FFA071',
          400: '#FF7A3D',
          500: '#FF5A1F',
          600: '#E84A10',
          700: '#C03A0A',
          800: '#97300F',
          900: '#7A2810',
        },
      },

      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
        display: ['"Sora"', 'Inter', 'system-ui', 'sans-serif'],
      },

      fontSize: {
        '8xl': ['6rem', { lineHeight: '1.05' }],
        '9xl': ['7.5rem', { lineHeight: '1.05' }],
      },

      borderRadius: {
        '4xl': '2rem',
      },

      maxWidth: {
        '8xl': '88rem',
      },

      animation: {
        'fade-up': 'fadeUp 0.6s ease-out forwards',
        'fade-in': 'fadeIn 0.5s ease-out forwards',
        'scale-in': 'scaleIn 0.3s ease-out forwards',
        'slide-in-right':
          'slideInRight 0.35s cubic-bezier(0.16,1,0.3,1) forwards',
        'marquee': 'marquee 30s linear infinite',
      },

      keyframes: {
        fadeUp: {
          '0%': {
            opacity: '0',
            transform: 'translateY(24px)',
          },
          '100%': {
            opacity: '1',
            transform: 'translateY(0)',
          },
        },

        fadeIn: {
          '0%': {
            opacity: '0',
          },
          '100%': {
            opacity: '1',
          },
        },

        scaleIn: {
          '0%': {
            opacity: '0',
            transform: 'scale(0.95)',
          },
          '100%': {
            opacity: '1',
            transform: 'scale(1)',
          },
        },

        slideInRight: {
          '0%': {
            opacity: '0',
            transform: 'translateX(32px)',
          },
          '100%': {
            opacity: '1',
            transform: 'translateX(0)',
          },
        },

        marquee: {
          '0%': {
            transform: 'translateX(0)',
          },
          '100%': {
            transform: 'translateX(-50%)',
          },
        },
      },
    },
  },

  plugins: [],
};