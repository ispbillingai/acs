
import React, { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Card } from '@/components/ui/card';
import { DeviceInfo } from '@/components/DeviceInfo';
import { DeviceActions } from '@/components/DeviceActions';
import { DeviceParameters } from '@/components/DeviceParameters';
import { ConnectedHosts } from '@/components/ConnectedHosts';

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
  connectedClients?: number;
  parameters?: Array<{
    name: string;
    value: string;
    type: string;
  }>;
  connectedHosts?: Array<{
    id?: string;
    ipAddress: string;
    hostname: string;
    macAddress?: string;
    lastSeen?: string;
    isActive?: boolean;
  }>;
}

const DeviceDetailsPage = () => {
  const { id } = useParams<{ id: string }>();
  const [device, setDevice] = useState<Device | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshTrigger, setRefreshTrigger] = useState(0);

  useEffect(() => {
    const fetchDeviceDetails = async () => {
      try {
        setLoading(true);
        const response = await fetch(`/backend/api/devices.php?id=${id}`);
        
        if (!response.ok) {
          throw new Error(`HTTP error: ${response.status}`);
        }
        
        const data = await response.json();
        setDevice(data);
      } catch (error) {
        console.error('Error fetching device details:', error);
        setError('Failed to load device details. Please try again later.');
      } finally {
        setLoading(false);
      }
    };

    if (id) {
      fetchDeviceDetails();
    }
  }, [id, refreshTrigger]);

  const handleRefresh = () => {
    setRefreshTrigger(prev => prev + 1);
  };

  if (loading) {
    return (
      <div className="p-6">
        <div className="max-w-7xl mx-auto">
          <p>Loading device details...</p>
        </div>
      </div>
    );
  }

  if (error || !device) {
    return (
      <div className="p-6">
        <div className="max-w-7xl mx-auto">
          <p className="text-error-dark">{error || 'Device not found'}</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6">
      <div className="max-w-7xl mx-auto space-y-8">
        <div className="flex justify-between items-center">
          <h1 className="text-3xl font-semibold tracking-tight">
            Device Details
          </h1>
          <DeviceActions device={device} onRefresh={handleRefresh} />
        </div>
        
        <DeviceInfo device={{
          status: device.status,
          manufacturer: device.manufacturer || 'N/A',
          model: device.model || 'N/A',
          serialNumber: device.serialNumber,
          softwareVersion: device.softwareVersion || 'N/A',
          hardwareVersion: device.hardwareVersion || 'N/A',
          ipAddress: device.ipAddress || 'N/A',
          lastContact: device.lastContact || 'N/A',
          connectedClients: device.connectedClients || 0,
          uptime: 'N/A'
        }} />
        
        <DeviceParameters 
          parameters={device.parameters || []} 
          deviceId={device.id}
        />
        
        <ConnectedHosts 
          deviceId={device.id}
          refreshTrigger={refreshTrigger}
        />
      </div>
    </div>
  );
};

export default DeviceDetailsPage;
