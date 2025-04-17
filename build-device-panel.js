
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

// Run vite build with the device panel config
try {
  console.log('Building DeviceConfigurationPanel...');
  execSync('npx vite build --config vite.config.device-panel.ts', { stdio: 'inherit' });
  
  console.log('Build completed successfully!');
  
  // Make sure dist directory exists
  const distDir = path.resolve(__dirname, 'dist');
  if (!fs.existsSync(distDir)) {
    fs.mkdirSync(distDir);
  }
  
  console.log('Device config panel build completed.');
} catch (error) {
  console.error('Build failed:', error);
  process.exit(1);
}
