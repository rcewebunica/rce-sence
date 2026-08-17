FROM node:18-alpine

WORKDIR /app

# Copiar dependencias del servidor
COPY sence-rce-server/package*.json ./
RUN npm install --production

# Copiar código fuente del servidor
COPY sence-rce-server/ ./

ENV PORT=3000
ENV NODE_ENV=production

EXPOSE 3000

CMD ["node", "server.js"]
