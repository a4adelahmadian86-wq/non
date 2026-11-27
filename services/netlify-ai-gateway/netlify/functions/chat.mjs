import { proxyChat } from "./proxy-chat.mjs";

export default async function chat(request) {
  return proxyChat(request, "text");
}
