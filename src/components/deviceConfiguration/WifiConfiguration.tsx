
import React, { useState } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { toast } from "sonner";
import { Wifi, HelpCircle, RefreshCw, ChevronDown, ChevronUp, Copy } from "lucide-react";
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from "@/components/ui/accordion";
import {
  Alert,
  AlertDescription,
  AlertTitle,
} from "@/components/ui/alert";

interface WifiConfigurationProps {
  deviceId: string;
  onSuccess?: (connectionRequest: any) => void;
}

const WifiConfiguration: React.FC<WifiConfigurationProps> = ({ 
  deviceId, 
  onSuccess 
}) => {
  const [wifiSSID, setWifiSSID] = useState('');
  const [wifiPassword, setWifiPassword] = useState('');
  const [configuring, setConfiguring] = useState(false);
  const [connectionRequest, setConnectionRequest] = useState<any>(null);
  const [expandedCommands, setExpandedCommands] = useState(false);

  const makeConfigRequest = async () => {
    setConfiguring(true);
    try {
      console.log(`Making wifi config request with SSID: ${wifiSSID}, Password length: ${wifiPassword.length}`);
      
      const formData = new FormData();
      formData.append('device_id', deviceId);
      formData.append('action', 'wifi');
      formData.append('ssid', wifiSSID);
      formData.append('password', wifiPassword);

      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        body: formData
      });

      if (!response.ok) {
        throw new Error(`Server returned ${response.status}: ${response.statusText}`);
      }

      const result = await response.json();
      console.log(`WiFi config response:`, result);

      if (result.success) {
        toast.success(result.message);
        
        if (result.connection_request) {
          setConnectionRequest(result.connection_request);
          if (onSuccess) {
            onSuccess(result.connection_request);
          }
        }
        
        toast.info(
          <div className="flex items-start gap-2">
            <AlertTriangle className="h-5 w-5 text-yellow-500 flex-shrink-0 mt-0.5" />
            <div>
              <p className="font-medium">TR-069 Configuration Workflow</p>
              <p className="text-sm">Complete TR-069 session requires these steps:</p>
              <ol className="text-sm list-decimal pl-5 mt-1">
                <li>Send a connection request (test multiple ports if needed)</li>
                <li>Wait for device to open a session with ACS (Inform)</li>
                <li>ACS responds with InformResponse and sends SetParameterValues</li>
                <li>For Huawei HG8145V5, use PreSharedKey.1.PreSharedKey (not KeyPassphrase)</li>
                <li>Send Commit command with matching CommandKey</li>
              </ol>
            </div>
          </div>,
          { duration: 15000 }
        );
      } else {
        toast.error(result.message || 'Configuration failed');
      }
    } catch (error) {
      console.error(`Error in wifi config:`, error);
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
    makeConfigRequest();
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
  
  return (
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
      
      <Alert className="mb-4 bg-amber-50 border-amber-200">
        <AlertTriangle className="h-4 w-4 text-amber-500" />
        <AlertTitle className="text-amber-800">TR-069 Configuration Notice</AlertTitle>
        <AlertDescription className="text-amber-700 text-sm">
          For Huawei HG8145V5 devices, WiFi settings must be updated using the TR-069 protocol with the correct parameter paths.
          After setting values here, use the Connection Request in the TR-069 tab to trigger a session.
        </AlertDescription>
      </Alert>
      
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
              Preparing TR-069 Request...
            </>
          ) : "Update WiFi"}
        </Button>
        
        <Accordion type="single" collapsible className="w-full">
          <AccordionItem value="tr069-info">
            <AccordionTrigger className="text-xs text-gray-500">
              TR-069 Implementation Details for Huawei HG8145V5
            </AccordionTrigger>
            <AccordionContent>
              <div className="text-xs text-gray-600 space-y-2">
                <p>For Huawei HG8145V5 devices, use these TR-098 parameters:</p>
                <ul className="list-disc pl-5 space-y-1">
                  <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.SSID</code> (2.4GHz WiFi name)</li>
                  <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.LANDevice.1.WLANConfiguration.1.PreSharedKey.1.PreSharedKey</code> (WiFi password)</li>
                  <li><code className="bg-slate-100 px-1 rounded">InternetGatewayDevice.ManagementServer.ConnectionRequestURL</code> (Contains the correct connection request port)</li>
                </ul>
                <p className="font-medium pt-2">Correct TR-069 workflow:</p>
                <ol className="list-decimal pl-5 space-y-1">
                  <li>Send connection request to device's ConnectionRequestURL (ports: 30005, 37215, 7547, 4567)</li>
                  <li>Device opens session with ACS by sending Inform</li>
                  <li>ACS responds with InformResponse</li>
                  <li>ACS sends SetParameterValues with correct TR-098 paths</li>
                  <li>Device responds with SetParameterValuesResponse (status 0 = success)</li>
                  <li>ACS sends Commit command with matching CommandKey</li>
                  <li>ACS verifies with GetParameterValues (optional)</li>
                </ol>
              </div>
            </AccordionContent>
          </AccordionItem>
        </Accordion>
      </div>
      
      {connectionRequest && (
        <div className="mt-4 p-3 border rounded-md bg-slate-50">
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
                      Try these commands if the default one fails. Huawei ONTs typically use connection request ports 30005 or 37215.
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default WifiConfiguration;
