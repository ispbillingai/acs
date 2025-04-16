
// API Configuration
export const config = {
  API_BASE_URL: '/backend/api',
  ACS_SETTINGS: {
    REFRESH_INTERVAL: 30000, // 30 seconds
    ONLINE_THRESHOLD: 10 * 60 * 1000 // 10 minutes, matching PHP backend
  }
};
