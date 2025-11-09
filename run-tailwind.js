#!/usr/bin/env node
import { spawn } from 'node:child_process';
spawn('node', ['node_modules/tailwindcss/lib/cli.js', ...process.argv.slice(2)], {
  stdio: 'inherit',
});