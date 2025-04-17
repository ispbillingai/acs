
import React, { useState, useEffect } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { toast } from "sonner";
import { PowerOff, AlertTriangle, RefreshCw, Clock } from "lucide-react";
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/components/ui/alert";
import { Checkbox } from "@/components/ui/checkbox";

interface RebootConfigurationProps {
  deviceId: string;
  onSuccess?: (connectionRequest: any) => void;
}

const RebootConfiguration: React.FC<RebootConfigurationProps> = ({ 
  deviceId,
  onSuccess
}) => {
  const [rebootReason, setRebootReason] = useState('User initiated reboot');
  const [configuring, setConfiguring] = useState(false);
  const [useVendorRpc, setUseVendorRpc] = useState(false);
  const [upTime, setUpTime] = useState<string | null>(null);
  const [isCheckingUptime, setIsCheckingUptime] = useState(false);
  const [rebootSuccessful, setRebootSuccessful] = useState(false);

  // Function to fetch the device's uptime
  const fetchUptime = async () => {
    try {
      setIsCheckingUptime(true);
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'get_parameter');
      formData.append('parameter', 'InternetGatewayDevice.DeviceInfo.UpTime');
      
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });
      
      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }
      
      const result = await response.json();
      console.log('Uptime response:', result);
      
      if (result.success && result.value) {
        setUpTime(result.value);
        return result.value;
      }
      
      return null;
    } catch (error) {
      console.error('Error fetching uptime:', error);
      return null;
    } finally {
      setIsCheckingUptime(false);
    }
  };

  // Check uptime on component mount
  useEffect(() => {
    fetchUptime();
  }, [deviceId]);

  const verifyRebootSuccess = async (initialUptime: string) => {
    console.log('Verifying reboot success, initial uptime:', initialUptime);
    
    // Wait 25 seconds before checking uptime again
    await new Promise(resolve => setTimeout(resolve, 25000));
    
    const newUptime = await fetchUptime();
    console.log('New uptime after reboot attempt:', newUptime);
    
    if (newUptime && parseInt(newUptime, 10) < 30) {
      // Device has rebooted successfully
      toast.success('Device reboot confirmed! New uptime: ' + newUptime + 's');
      setRebootSuccessful(true);
    } else {
      toast.error('Device may not have rebooted. Please check the device manually.');
      setRebootSuccessful(false);
    }
  };

  const makeConfigRequest = async () => {
    setConfiguring(true);
    
    // First, get the current uptime
    const currentUptime = await fetchUptime();
    
    if (!currentUptime) {
      toast.error('Could not fetch current uptime. Proceeding with reboot anyway.');
    } else {
      console.log(`Current uptime before reboot: ${currentUptime}`);
    }
    
    try {
      console.log(`Making reboot request with reason: ${rebootReason}, vendor RPC: ${useVendorRpc}`);
      
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'reboot');
      formData.append('reason', rebootReason);
      if (useVendorRpc) {
        formData.append('use_vendor_rpc', 'true');
      }

      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });

      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();
      console.log(`Reboot config response:`, result);

      if (result.success) {
        toast.success(result.message);
        
        if (result.connection_request && onSuccess) {
          onSuccess(result.connection_request);
        }
        
        toast.info(
          <div className="space-y-2">
            <p className="font-medium">Reboot Command Sent</p>
            <p className="text-sm">The device will reboot after the TR-069 session ends.</p>
            <p className="text-sm">Checking reboot status in 25 seconds...</p>
          </div>,
          { duration: 10000 }
        );
        
        // Verify reboot after sending command
        if (currentUptime) {
          verifyRebootSuccess(currentUptime);
        }
      } else {
        toast.error(result.message || 'Reboot configuration failed');
      }
    } catch (error) {
      console.error(`Error in reboot config:`, error);
      toast.error('Reboot configuration failed due to server error');
    } finally {
      setConfiguring(false);
    }
  };

  const handleReboot = () => {
    console.log("Reboot button clicked");
    if (window.confirm(`Are you sure you want to reboot this device? Reason: ${rebootReason}`)) {
      makeConfigRequest();
    }
  };

  const formatUptime = (seconds: string) => {
    const secs = parseInt(seconds, 10);
    const days = Math.floor(secs / 86400);
    const hours = Math.floor((secs % 86400) / 3600);
    const minutes = Math.floor((secs % 3600) / 60);
    const remainingSecs = secs % 60;
    
    return `${days}d ${hours}h ${minutes}m ${remainingSecs}s (${seconds}s total)`;
  };

  return (
    <div>
      <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
        <PowerOff className="h-5 w-5" />
        Device Reboot
      </h3>
      
      <div className="space-y-4">
        <Alert className="bg-red-50 border-red-200">
          <AlertTriangle className="h-4 w-4 text-red-500" />
          <AlertTitle className="text-red-800">Device Reboot Warning</AlertTitle>
          <AlertDescription className="text-red-700 text-sm">
            Rebooting the device will disconnect all users and services. This operation typically takes 1-2 minutes to complete.
            After reboot, the device will reconnect automatically to the ACS server.
          </AlertDescription>
        </Alert>
        
        {upTime && (
          <div className="flex items-center space-x-2 bg-blue-50 p-3 rounded-md border border-blue-200">
            <Clock className="h-4 w-4 text-blue-500" />
            <div>
              <span className="text-sm font-medium text-blue-800">Current Uptime: </span>
              <span className="text-sm text-blue-700">{formatUptime(upTime)}</span>
              <Button 
                variant="outline" 
                size="sm" 
                className="ml-2"
                onClick={fetchUptime}
                disabled={isCheckingUptime}
              >
                {isCheckingUptime ? (
                  <>
                    <RefreshCw className="h-3 w-3 mr-1 animate-spin" />
                    Checking...
                  </>
                ) : (
                  <>
                    <RefreshCw className="h-3 w-3 mr-1" />
                    Refresh
                  </>
                )}
              </Button>
            </div>
          </div>
        )}
        
        {rebootSuccessful && (
          <Alert className="bg-green-50 border-green-200">
            <div className="h-4 w-4 text-green-500">✓</div>
            <AlertTitle className="text-green-800">Reboot Confirmed</AlertTitle>
            <AlertDescription className="text-green-700 text-sm">
              The device has successfully rebooted as confirmed by the uptime check.
            </AlertDescription>
          </Alert>
        )}
        
        <div className="space-y-2">
          <Label htmlFor="reboot-reason">Reboot Reason (optional)</Label>
          <Input
            id="reboot-reason"
            placeholder="User initiated reboot"
            value={rebootReason}
            onChange={(e) => setRebootReason(e.target.value)}
          />
          <p className="text-xs text-gray-500">This will be logged in the device's reboot history.</p>
        </div>
        
        <div className="flex items-center space-x-2">
          <Checkbox 
            id="use-vendor-rpc" 
            checked={useVendorRpc} 
            onCheckedChange={(checked) => setUseVendorRpc(checked === true)}
          />
          <Label htmlFor="use-vendor-rpc" className="text-sm">
            Use Huawei vendor-specific reboot RPC (X_HW_DelayReboot)
          </Label>
        </div>
        <p className="text-xs text-gray-500 -mt-3 ml-6">
          Try this option if standard reboot doesn't work with your Huawei device.
        </p>
        
        <Button 
          variant="destructive" 
          onClick={handleReboot}
          className="w-full md:w-auto"
          disabled={configuring}
        >
          {configuring ? (
            <>
              <RefreshCw className="h-4 w-4 mr-2 animate-spin" />
              Processing...
            </>
          ) : (
            <>
              <PowerOff className="h-4 w-4 mr-2" />
              Reboot Device
            </>
          )}
        </Button>
      </div>
    </div>
  );
};

export default RebootConfiguration;
