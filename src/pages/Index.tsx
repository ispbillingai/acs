
import React, { useEffect, useState } from 'react';
import { DeviceStats } from '../components/DeviceStats';
import { DeviceCard } from '../components/DeviceCard';

interface Device {
  id: string;
  serialNumber: string;
  manufacturer: string;
  model: string;
  status: 'online' | 'offline' | 'provisioning';
  lastContact: string;
  ipAddress: string;
  softwareVersion?: string;
  hardwareVersion?: string;
}

const Index = () => {
  const [devices, setDevices] = useState<Device[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const fetchDevices = async () => {
      try {
        const response = await fetch('/backend/api/devices.php');
        const data = await response.json();
        setDevices(data);
      } catch (error) {
        console.error('Error fetching devices:', error);
      } finally {
        setIsLoading(false);
      }
    };

    fetchDevices();
    
    // Refresh data every 5 seconds
    const interval = setInterval(fetchDevices, 5000);
    return () => clearInterval(interval);
  }, []);

  if (isLoading) {
    return (
      <div className="p-6">
        <div className="max-w-7xl mx-auto">
          <p>Loading devices...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6">
      <div className="max-w-7xl mx-auto space-y-8">
        <div className="space-y-2">
          <h1 className="text-3xl font-semibold tracking-tight">
            ACS Dashboard
          </h1>
          <p className="text-gray-600">
            Monitor and manage your TR-069 devices
          </p>
        </div>
        <DeviceStats devices={devices} />
        {devices.map((device) => (
          <DeviceCard key={device.id} device={device} />
        ))}
      </div>
    </div>
  );
};

export default Index;
