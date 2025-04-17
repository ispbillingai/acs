
import React, { useState, useEffect } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "sonner";
import { Wifi, Globe, PowerOff, AlertTriangle, Lock, HelpCircle, RefreshCw, ChevronDown, ChevronUp, Copy } from "lucide-react";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from "@/components/ui/tabs";

interface DeviceConfigurationPanelProps {
  deviceId: string;
}

export const DeviceConfigurationPanel: React.FC<DeviceConfigurationPanelProps> = ({ deviceId }) => {
  const [wifiSSID, setWifiSSID] = useState('');
  const [wifiPassword, setWifiPassword] = useState('');
  const [wanIPAddress, setWanIPAddress] = useState('');
  const [wanGateway, setWanGateway] = useState('');
  const [connectionRequestUsername, setConnectionRequestUsername] = useState('');
  const [connectionRequestPassword, setConnectionRequestPassword] = useState('');
  const [loading, setLoading] = useState(false);
  const [configuring, setConfiguring] = useState(false);
  const [connectionRequest, setConnectionRequest] = useState<any>(null);
  const [expandedCommands, setExpandedCommands] = useState(false);

  useEffect(() => {
    console.log("DeviceConfigurationPanel mounted with deviceId:", deviceId);
    
    const fetchDeviceSettings = async () => {
      try {
        setLoading(true);
        const formData = new FormData();
        formData.append('device_id', deviceId);
        formData.append('action', 'get_settings');
        
        const response = await fetch('/backend/api/device_configure.php', {
          method: 'POST',
          body: formData
        });
        
        const result = await response.json();
        console.log("Fetched device settings:", result);
        
        if (result.success && result.settings) {
          setWifiSSID(result.settings.ssid || '');
          setWifiPassword(result.settings.password || '');
          setWanIPAddress(result.settings.ip_address || '');
          setWanGateway(result.settings.gateway || '');
          setConnectionRequestUsername(result.settings.connection_request_username || '');
          setConnectionRequestPassword(result.settings.connection_request_password || '');
        } else {
          toast.error("Failed to load device settings");
        }
      } catch (error) {
        console.error('Error fetching device settings:', error);
        toast.error("Error loading device settings");
      } finally {
        setLoading(false);
      }
    };
    
    fetchDeviceSettings();
  }, [deviceId]);

  const makeConfigRequest = async (action: string, data: Record<string, string>) => {
    setConfiguring(true);
    try {
      console.log(`Making ${action} config request with data:`, data);
      
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

      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();
      console.log(`${action} config response:`, result);

      if (result.success) {
        toast.success(result.message);
        
        if (result.connection_request) {
          setConnectionRequest(result.connection_request);
        }
        
        if (action === 'wifi') {
          toast.info(
            <div className="flex items-start gap-2">
              <AlertTriangle className="h-5 w-5 text-yellow-500 flex-shrink-0 mt-0.5" />
              <div>
                <p className="font-medium">TR-069 Configuration Workflow</p>
                <p className="text-sm">Complete TR-069 session requires these steps:</p>
                <ol className="text-sm list-decimal pl-5 mt-1">
                  <li>Send a connection request to the device using the correct port (try all options if needed)</li>
                  <li>Wait for device to open a session with ACS (Inform)</li>
                  <li>ACS responds with InformResponse and sends SetParameterValues</li>
                  <li>For Huawei HG8145V5, use PreSharedKey instead of KeyPassphrase for passwords</li>
                  <li>Send Commit command after SetParameterValues</li>
                </ol>
                <p className="text-sm mt-1 italic">Try several connection request URLs if the first fails.</p>
              </div>
            </div>,
            { duration: 15000 }
          );
        }
      } else {
        toast.error(result.message || 'Configuration failed');
      }
    } catch (error) {
      console.error(`Error in ${action} config:`, error);
      toast.error('Configuration failed due to server error');
    } finally {
      setConfiguring(false);
    }
  };

  const handleWiFiUpdate = () => {
    console.log("WiFi update button clicked");
    if (!wifiSSID.trim()) {
      toast.error('WiFi SSID cannot be empty');
      return;
    }
    makeConfigRequest('wifi', { ssid: wifiSSID, password: wifiPassword });
  };

  const handleWANUpdate = () => {
    console.log("WAN update button clicked");
    if (!wanIPAddress.trim()) {
      toast.error('IP Address cannot be empty');
      return;
    }
    makeConfigRequest('wan', { ip_address: wanIPAddress, gateway: wanGateway });
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
    makeConfigRequest('connection_request', { 
      username: connectionRequestUsername, 
      password: connectionRequestPassword 
    });
  };

  const handleReboot = () => {
    console.log("Reboot button clicked");
    if (window.confirm('Are you sure you want to reboot this device?')) {
      makeConfigRequest('reboot', {});
    }
  };

  const showTR069Info = () => {
    toast.info(
      <div className="space-y-2">
        <p className="font-medium">About TR-069 Configuration</p>
        <p className="text-sm">TR-069 uses a data model with parameters organized in a tree structure.</p>
        <p className="text-sm">For Huawei HG8145V5 devices, we use the TR-098 data model that starts with "InternetGatewayDevice".</p>
        <p className="text-sm">Key Huawei HG8145V5 parameters:</p>
        <ul className="text-sm list-disc pl-5">
          <li>WiFi SSID: InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</li>
          <li>WiFi Password: InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey</li>
          <li>Connection Request URL: InternetGatewayDevice.ManagementServer.ConnectionRequestURL</li>
        </ul>
        <p className="text-sm">Common connection request ports: 30005, 37215, 7547, 4567</p>
      </div>,
      { duration: 15000 }
    );
  };

  const copyToClipboard = (text: string) => {
    navigator.clipboard.writeText(text);
    toast.success("Command copied to clipboard");
  };

  if (loading) {
    return (
      <div className="space-y-6 p-4 border rounded-lg bg-white shadow-sm flex items-center justify-center h-64">
        <div className="text-center">
          <div className="animate-spin h-8 w-8 border-4 border-primary border-t-transparent rounded-full mx-auto mb-4"></div>
          <p className="text-gray-500">Loading device settings...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6 p-4 border rounded-lg bg-white shadow-sm">
      <Tabs defaultValue="wifi" className="w-full">
        <TabsList className="w-full">
          <TabsTrigger value="wifi" className="flex-1"><Wifi className="h-4 w-4 mr-2" /> WiFi</TabsTrigger>
          <TabsTrigger value="wan" className="flex-1"><Globe className="h-4 w-4 mr-2" /> WAN</TabsTrigger>
          <TabsTrigger value="control" className="flex-1"><PowerOff className="h-4 w-4 mr-2" /> Control</TabsTrigger>
        </TabsList>
        
        <TabsContent value="wifi" className="pt-4">
          <div>
            <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
              <Wifi className="h-5 w-5" />
              WiFi Configuration
              <Button 
                variant="ghost" 
                size="icon" 
                className="h-5 w-5 rounded-full" 
                onClick={showTR069Info}
              >
                <HelpCircle className="h-4 w-4" />
              </Button>
            </h3>
            
            {connectionRequest && (
              <div className="mb-4 p-3 border rounded-md bg-slate-50">
                <h4 className="text-sm font-semibold mb-2 flex items-center">
                  <RefreshCw className="h-4 w-4 mr-1" /> TR-069 Connection Request Details
                </h4>
                <div className="text-xs space-y-1 font-mono bg-slate-100 p-2 rounded">
                  <div className="flex justify-between">
                    <div><span className="text-slate-500">URL:</span> {connectionRequest.url}</div>
                    <button 
                      onClick={() => copyToClipboard(connectionRequest.url)}
                      className="text-blue-500 hover:text-blue-700"
                    >
                      <Copy className="h-3 w-3" />
                    </button>
                  </div>
                  <div><span className="text-slate-500">Username:</span> {connectionRequest.username}</div>
                  <div><span className="text-slate-500">Password:</span> {connectionRequest.password}</div>
                  
                  <div className="pt-2 mt-1 border-t border-slate-200">
                    <div className="flex justify-between items-center">
                      <div className="text-slate-500">Connection Request Command:</div>
                      <button 
                        onClick={() => copyToClipboard(connectionRequest.command)}
                        className="text-blue-500 hover:text-blue-700"
                      >
                        <Copy className="h-3 w-3" />
                      </button>
                    </div>
                    <div className="break-all bg-black text-white p-1 rounded mt-1">
                      {connectionRequest.command}
                    </div>
                  </div>
                  
                  {connectionRequest.alternative_commands && (
                    <div className="pt-2 mt-2 border-t border-slate-200">
                      <div 
                        className="flex justify-between items-center cursor-pointer text-slate-500 hover:text-slate-700"
                        onClick={() => setExpandedCommands(!expandedCommands)}
                      >
                        <span>Alternative connection request URLs to try:</span>
                        {expandedCommands ? <ChevronUp className="h-3 w-3" /> : <ChevronDown className="h-3 w-3" />}
                      </div>
                      
                      {expandedCommands && (
                        <div className="mt-2 space-y-2">
                          {connectionRequest.alternative_commands.map((cmd: string, idx: number) => (
                            <div key={idx} className="break-all bg-black text-white p-1 rounded relative">
                              <div className="absolute right-1 top-1">
                                <button 
                                  onClick={() => copyToClipboard(cmd)}
                                  className="text-blue-300 hover:text-blue-100 bg-black bg-opacity-70 rounded p-0.5"
                                >
                                  <Copy className="h-3 w-3" />
                                </button>
                              </div>
                              <div className="pl-1 pr-8">{cmd}</div>
                            </div>
                          ))}
                          <div className="text-xs text-yellow-600 italic">
                            Try these commands if the default one fails. Huawei ONTs use different connection request ports.
                          </div>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>
            )}
            
            <div className="space-y-3">
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
              <Button 
                onClick={handleWiFiUpdate}
                className="w-full md:w-auto"
                disabled={configuring}
              >
                {configuring ? (
                  <>
                    <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
                    Updating...
                  </>
                ) : "Update WiFi"}
              </Button>
              
              <Accordion type="single" collapsible className="w-full">
                <AccordionItem value="tr069-info">
                  <AccordionTrigger className="text-xs text-gray-500">
                    TR-069 Implementation Details
                  </AccordionTrigger>
                  <AccordionContent>
                    <div className="text-xs text-gray-600 space-y-2">
                      <p>For Huawei HG8145V5 devices, use these TR-098 parameters:</p>
                      <ul className="list-disc pl-5 space-y-1">
                        <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</code> (2.4GHz WiFi name)</li>
                        <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey</code> (WiFi password)</li>
                        <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.ManagementServer.ConnectionRequestURL</code> (Contains the correct connection request port)</li>
                      </ul>
                      <p>The correct TR-069 workflow requires:</p>
                      <ol className="list-decimal pl-5 space-y-1">
                        <li>Send connection request to device's ConnectionRequestURL (try ports 30005, 37215, 7547, 4567)</li>
                        <li>Device opens session with ACS by sending Inform</li>
                        <li>ACS responds with InformResponse</li>
                        <li>ACS sends SetParameterValues with correct TR-098 paths</li>
                        <li>ACS sends Commit command</li>
                        <li>Optionally, ACS verifies with GetParameterValues</li>
                      </ol>
                      <p className="text-amber-600 font-semibold">Common Issues:</p>
                      <ul className="list-disc pl-5 space-y-1">
                        <li>Wrong data model: Use TR-098 paths starting with InternetGatewayDevice, not TR-181 paths</li>
                        <li>Wrong password parameter: Use PreSharedKey.1.PreSharedKey, not KeyPassphrase</li>
                        <li>Wrong port: Try multiple connection request ports if the default doesn't work</li>
                        <li>Missing Commit: Some devices require an explicit Commit command</li>
                      </ul>
                    </div>
                  </AccordionContent>
                </AccordionItem>
              </Accordion>
            </div>
          </div>
        </TabsContent>
        
        <TabsContent value="wan" className="pt-4">
          <div>
            <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
              <Globe className="h-5 w-5" />
              WAN Configuration
            </h3>
            <div className="space-y-3">
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
              <Button 
                onClick={handleWANUpdate}
                className="w-full md:w-auto"
                disabled={configuring}
              >
                {configuring ? (
                  <>
                    <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
                    Updating...
                  </>
                ) : "Update WAN"}
              </Button>
            </div>
          </div>
        </TabsContent>
        
        <TabsContent value="control" className="pt-4">
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
            
            <div className="mt-6">
              <h3 className="text-lg font-bold mb-3 flex items-center gap-2">
                <PowerOff className="h-5 w-5" />
                Device Control
              </h3>
              <Button 
                variant="destructive" 
                onClick={handleReboot}
                className="w-full md:w-auto"
                disabled={configuring}
              >
                {configuring ? (
                  <>
                    <span className="animate-spin h-4 w-4 border-2 border-current border-t-transparent rounded-full mr-2"></span>
                    Processing...
                  </>
                ) : "Reboot Device"}
              </Button>
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
};

export default DeviceConfigurationPanel;
