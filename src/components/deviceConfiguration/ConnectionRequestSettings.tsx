
import React, { useState } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "sonner";
import { Lock } from "lucide-react";

interface ConnectionRequestSettingsProps {
  deviceId: string;
}

const ConnectionRequestSettings: React.FC<ConnectionRequestSettingsProps> = ({ deviceId }) => {
  const [connectionRequestUsername, setConnectionRequestUsername] = useState('');
  const [connectionRequestPassword, setConnectionRequestPassword] = useState('');
  const [configuring, setConfiguring] = useState(false);

  const makeConfigRequest = async () => {
    setConfiguring(true);
    try {
      console.log(`Making connection request settings update`);
      
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'connection_request');
      formData.append('username', connectionRequestUsername);
      formData.append('password', connectionRequestPassword);

      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });

      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();
      console.log(`Connection request config response:`, result);

      if (result.success) {
        toast.success(result.message);
      } else {
        toast.error(result.message || 'Configuration failed');
      }
    } catch (error) {
      console.error(`Error in connection request config:`, error);
      toast.error('Configuration failed due to server error');
    } finally {
      setConfiguring(false);
    }
  };

  const handleConnectionRequestUpdate = () => {
    console.log("Connection Request update button clicked");
    if (!connectionRequestUsername.trim()) {
      toast.error('Connection Request Username cannot be empty');
      return;
    }
    if (!connectionRequestPassword.trim()) {
      toast.error('Connection Request Password cannot be empty');
      return;
    }
    makeConfigRequest();
  };

  return (
    <div>
      <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
        <Lock className="h-5 w-5" />
        Connection Request Settings
      </h3>
      <div className="space-y-3">
        <Input
          placeholder="Connection Request Username"
          value={connectionRequestUsername}
          onChange={(e) => setConnectionRequestUsername(e.target.value)}
        />
        <Input
          type="password"
          placeholder="Connection Request Password"
          value={connectionRequestPassword}
          onChange={(e) => setConnectionRequestPassword(e.target.value)}
        />
        <Button 
          onClick={handleConnectionRequestUpdate}
          className="w-full md:w-auto"
          disabled={configuring}
        >
          {configuring ? (
            <>
              <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
              Updating...
            </>
          ) : "Update Connection Settings"}
        </Button>
        <p className="text-xs text-gray-500 mt-1">
          These credentials are used by the ACS to authenticate when making connection requests to the device.
        </p>
      </div>
    </div>
  );
};

export default ConnectionRequestSettings;
