
import { useState, useEffect } from 'react';

interface ConfigurationData {
  wifi?: {
    ssid?: string;
    security?: string;
  };
  wan?: {
    connectionType?: string;
    ipAddress?: string;
    status?: string;
  };
}

export function useConfigurationPolling(deviceId: string, interval: number = 30000) {
  const [configuration, setConfiguration] = useState<ConfigurationData>({});
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchConfiguration = async () => {
      try {
        const formData = new FormData();
        formData.append('device_id', deviceId);
        formData.append('action', 'get_settings');
        
        const response = await fetch('/backend/api/device_configure.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        
        if (result.success && result.settings) {
          setConfiguration(result.settings);
          setError(null);
        }
      } catch (err) {
        setError('Failed to fetch configuration updates');
        console.error('Error fetching configuration:', err);
      }
    };

    // Fetch immediately on mount
    fetchConfiguration();

    // Set up polling interval
    const pollTimer = setInterval(fetchConfiguration, interval);

    return () => clearInterval(pollTimer);
  }, [deviceId, interval]);

  return { configuration, error };
}
