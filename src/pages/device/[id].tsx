
import React from 'react';
import { useParams } from 'react-router-dom';
import { Device } from '@/types';
import { DeviceInfo } from '@/components/DeviceInfo';
import { DeviceActions } from '@/components/DeviceActions';
import { ConnectedClientsTable } from '@/components/ConnectedClientsTable';
import { DeviceParameters } from '@/components/DeviceParameters';
import { DeviceStats } from '@/components/DeviceStats';
import { OpticalReadings } from '@/components/OpticalReadings';

const DevicePage = () => {
  const { id } = useParams<{ id: string }>();
  
  // This would typically fetch data from an API
  const [device, setDevice] = React.useState<Device | null>(null);
  const [isLoading, setIsLoading] = React.useState(true);
  const [error, setError] = React.useState<string | null>(null);

  React.useEffect(() => {
    // In a real app, fetch actual device data from an API
    const fetchDevice = async () => {
      try {
        setIsLoading(true);
        // Simulate API call with timeout
        setTimeout(() => {
          // Mock data for preview
          const mockDevice: Device = {
            id: id || '1',
            serialNumber: 'SN12345678',
            manufacturer: 'Huawei',
            model: 'HG8546',
            softwareVersion: '1.0.5',
            hardwareVersion: '2.1',
            status: 'online',
            lastContact: new Date().toISOString(),
            ipAddress: '192.168.1.1',
            ssid: 'HomeNetwork',
            uptime: '259200', // 3 days in seconds
            connectedClients: 3,
            txPower: '-5.2 dBm',
            rxPower: '-23.4 dBm'
          };
          
          setDevice(mockDevice);
          setIsLoading(false);
        }, 1000);
      } catch (err) {
        setError('Failed to load device data');
        setIsLoading(false);
      }
    };

    fetchDevice();
  }, [id]);

  const handleRefresh = () => {
    // Re-fetch data
    if (id) {
      // This would trigger a new API call in a real app
      setIsLoading(true);
      setTimeout(() => {
        setIsLoading(false);
      }, 1000);
    }
  };

  const handleRefreshOptical = () => {
    // Specifically refresh optical readings
    if (id && device) {
      console.log("Refreshing optical readings for device:", id);
      // Simulate refreshing optical readings
      setTimeout(() => {
        setDevice({
          ...device,
          txPower: `-${Math.random() * 10 + 1 }.${Math.floor(Math.random() * 10)} dBm`,
          rxPower: `-${Math.random() * 20 + 10}.${Math.floor(Math.random() * 10)} dBm`
        });
      }, 1000);
    }
  };

  if (isLoading) {
    return <div className="p-8 text-center">Loading device data...</div>;
  }

  if (error) {
    return <div className="p-8 text-center text-red-500">{error}</div>;
  }

  if (!device) {
    return <div className="p-8 text-center">Device not found</div>;
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <h1 className="text-2xl font-bold">Device {device.serialNumber}</h1>
        <DeviceActions 
          device={device} 
          onRefresh={handleRefresh}
          onRefreshOptical={handleRefreshOptical} 
        />
      </div>
      
      <DeviceStats device={device} />
      
      <DeviceInfo device={device} />
      
      <OpticalReadings device={device} onRefresh={handleRefreshOptical} />
      
      <ConnectedClientsTable deviceId={device.id} />
      
      <DeviceParameters deviceId={device.id} />
    </div>
  );
};

export default DevicePage;
