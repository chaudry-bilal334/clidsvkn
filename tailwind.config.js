/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        clinical: {
          slate: {
            50: '#f8f9fa',
            100: '#f1f3f5',
            200: '#e4e7eb',
            300: '#d1d5d9',
            400: '#a3a8ad',
            500: '#73787d',
            600: '#52565a',
            700: '#3f4245',
            800: '#2e3032',
            900: '#1e2124',
            950: '#0d0f11',
          },
          indigo: {
            50: '#eef2ff',
            100: '#e2e8f5',
            200: '#cbd5e1',
            300: '#a5b4fc',
            400: '#818cf8',
            500: '#6366f1',
            600: '#4f46e5',
            700: '#4338ca',
            800: '#3730a3',
            900: '#312e81',
            950: '#1e1b4b',
          },
          teal: {
            50: '#f0fdfa',
            100: '#ccfbf1',
            200: '#99f6e4',
            300: '#5eead4',
            400: '#2dd4bf',
            500: '#14b8a6',
            600: '#0d9488',
            700: '#0f766e',
            800: '#10b981',
            900: '#059669',
            950: '#042f2f',
          },
        },
      },
      fontFamily: {
        sans: ['Inter', 'system-ui', 'sans-serif'],
        mono: ['JetBrains Mono', 'monospace'],
      },
    },
  },
  plugins: [],
}
