
import React, { useState } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "sonner";

interface DeviceConfigurationPanelProps {
  deviceId: string;
}

export const DeviceConfigurationPanel: React.FC<DeviceConfigurationPanelProps> = ({ deviceId }) => {
  const [wifiSSID, setWifiSSID] = useState('');
  const [wifiPassword, setWifiPassword] = useState('');
  const [wanIPAddress, setWanIPAddress] = useState('');
  const [wanGateway, setWanGateway] = useState('');

  const makeConfigRequest = async (action: string, data: Record<string, string>) => {
    try {
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', action);

      Object.entries(data).forEach(([key, value]) => {
        formData.append(key, value);
      });

      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        toast.success(result.message);
      } else {
        toast.error(result.message);
      }
    } catch (error) {
      toast.error('Configuration failed');
      console.error(error);
    }
  };

  const handleWiFiUpdate = () => {
    makeConfigRequest('wifi', { ssid: wifiSSID, password: wifiPassword });
  };

  const handleWANUpdate = () => {
    makeConfigRequest('wan', { ip_address: wanIPAddress, gateway: wanGateway });
  };

  const handleReboot = () => {
    if (window.confirm('Are you sure you want to reboot this device?')) {
      makeConfigRequest('reboot', {});
    }
  };

  return (
    <div className="space-y-4">
      <div>
        <h3 className="text-lg font-bold mb-2">WiFi Configuration</h3>
        <div className="space-y-2">
          <Input
            placeholder="WiFi Network Name"
            value={wifiSSID}
            onChange={(e) => setWifiSSID(e.target.value)}
          />
          <Input
            type="password"
            placeholder="WiFi Password"
            value={wifiPassword}
            onChange={(e) => setWifiPassword(e.target.value)}
          />
          <Button onClick={handleWiFiUpdate}>Update WiFi</Button>
        </div>
      </div>

      <div>
        <h3 className="text-lg font-bold mb-2">WAN Configuration</h3>
        <div className="space-y-2">
          <Input
            placeholder="IP Address"
            value={wanIPAddress}
            onChange={(e) => setWanIPAddress(e.target.value)}
          />
          <Input
            placeholder="Gateway"
            value={wanGateway}
            onChange={(e) => setWanGateway(e.target.value)}
          />
          <Button onClick={handleWANUpdate}>Update WAN</Button>
        </div>
      </div>

      <div>
        <h3 className="text-lg font-bold mb-2">Device Control</h3>
        <Button variant="destructive" onClick={handleReboot}>Reboot Device</Button>
      </div>
    </div>
  );
};
