import { loadConfig } from 'tailwindcss/loadConfig.js';
import path from 'path';

const configPath = path.resolve('./tailwind.config.ts');
const config = loadConfig(configPath);

console.log('✅ Loaded Tailwind configuration:');
console.log(JSON.stringify(config.theme?.screens, null, 2));