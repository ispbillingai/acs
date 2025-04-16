
import React, { useState, useEffect } from 'react';
import { useParams } from 'react-router-dom';
import { DeviceInfo } from '../../components/DeviceInfo';
import { DeviceParameters } from '../../components/DeviceParameters';
import { DeviceActions } from '../../components/DeviceActions';
import { ConnectedHosts } from '../../components/ConnectedHosts';
import { useToast } from '@/hooks/use-toast';

interface Device {
  id: string;
  serialNumber: string;
  manufacturer: string;
  model: string;
  status: 'online' | 'offline' | 'provisioning';
  lastContact: string;
  ipAddress: string;
  parameters: any[];
}

const DeviceDetail = () => {
  const { id } = useParams<{ id: string }>();
  const [device, setDevice] = useState<Device | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshTrigger, setRefreshTrigger] = useState(0);
  const { toast } = useToast();

  useEffect(() => {
    const fetchDevice = async () => {
      try {
        setLoading(true);
        const response = await fetch(`/backend/api/devices.php?id=${id}`);
        if (!response.ok) {
          throw new Error('Failed to fetch device');
        }
        const data = await response.json();
        setDevice(data);
      } catch (error) {
        console.error('Error fetching device:', error);
        toast({
          title: "Error",
          description: "Failed to load device details",
          variant: "destructive",
        });
      } finally {
        setLoading(false);
      }
    };

    if (id) {
      fetchDevice();
    }
  }, [id, refreshTrigger, toast]);

  const handleRefresh = () => {
    setRefreshTrigger(prev => prev + 1);
  };

  if (loading) {
    return (
      <div className="p-6">
        <div className="max-w-7xl mx-auto">
          <p>Loading device...</p>
        </div>
      </div>
    );
  }

  if (!device) {
    return (
      <div className="p-6">
        <div className="max-w-7xl mx-auto">
          <p>Device not found</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6">
      <div className="max-w-7xl mx-auto space-y-6">
        <div className="space-y-2">
          <h1 className="text-3xl font-semibold tracking-tight">
            Device Details
          </h1>
          <p className="text-gray-600">
            View and manage this TR-069 device
          </p>
        </div>

        <DeviceInfo device={device} onRefresh={handleRefresh} />
        
        <ConnectedHosts deviceId={id || ''} refreshTrigger={refreshTrigger} />
        
        <DeviceParameters deviceId={id || ''} />
        
        <DeviceActions device={device} />
      </div>
    </div>
  );
};

export default DeviceDetail;
