import type { Config } from 'tailwindcss'

export default {
  darkMode: 'class',
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.vue',
    './resources/**/*.js',
    './resources/**/*.ts',
  ],
  theme: {
    extend: {},
  },
} satisfies Config